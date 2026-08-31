<?php

namespace App\Actions\Fleet;

use App\Enums\AgencyDistanceSourceType;
use App\Models\Agency;
use App\Models\AgencyDistance;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageAgencyDistance
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /** @return Collection<int, AgencyDistance> */
    public function create(array $data, User $actor): Collection
    {
        $this->authorize($actor);
        $fromId = (int) $data['from_agency_id'];
        $toId = (int) $data['to_agency_id'];
        $bothWays = (bool) $data['same_distance_both_ways'];

        return DB::transaction(function () use ($actor, $data, $fromId, $toId, $bothWays): Collection {
            $this->lockActiveAgencies($fromId, $toId);
            $pairs = $bothWays ? [[$fromId, $toId], [$toId, $fromId]] : [[$fromId, $toId]];
            $this->lockPairs($pairs);

            foreach ($pairs as [$origin, $destination]) {
                if (AgencyDistance::query()
                    ->where('from_agency_id', $origin)
                    ->where('to_agency_id', $destination)
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'from_agency_id' => 'Cette distance directionnelle existe déjà. Corrigez la ligne existante.',
                    ]);
                }
            }

            return collect($pairs)->map(function (array $pair) use ($actor, $data): AgencyDistance {
                $distance = AgencyDistance::query()->create([
                    'from_agency_id' => $pair[0],
                    'to_agency_id' => $pair[1],
                    ...$this->verifiedValues($data, $actor),
                    'active' => true,
                ]);
                $this->audit->record(
                    'fleet.agency_distance.created',
                    $distance,
                    [],
                    $this->auditValues($distance),
                );

                return $distance;
            });
        });
    }

    /** @return Collection<int, AgencyDistance> */
    public function correct(AgencyDistance $distance, array $data, User $actor): Collection
    {
        $this->authorize($actor, $distance);
        $bothWays = (bool) $data['same_distance_both_ways'];

        return DB::transaction(function () use ($actor, $bothWays, $data, $distance): Collection {
            $this->lockActiveAgencies($distance->from_agency_id, $distance->to_agency_id);
            $pairs = $bothWays
                ? [
                    [$distance->from_agency_id, $distance->to_agency_id],
                    [$distance->to_agency_id, $distance->from_agency_id],
                ]
                : [[$distance->from_agency_id, $distance->to_agency_id]];
            $this->lockPairs($pairs);

            return collect($pairs)->map(function (array $pair) use ($actor, $data, $distance): AgencyDistance {
                $locked = AgencyDistance::query()
                    ->where('from_agency_id', $pair[0])
                    ->where('to_agency_id', $pair[1])
                    ->lockForUpdate()
                    ->first();

                if ($locked === null) {
                    $locked = AgencyDistance::query()->create([
                        'from_agency_id' => $pair[0],
                        'to_agency_id' => $pair[1],
                        ...$this->verifiedValues($data, $actor),
                        'active' => $distance->active,
                    ]);
                    $this->audit->record(
                        'fleet.agency_distance.created',
                        $locked,
                        [],
                        $this->auditValues($locked),
                    );

                    return $locked;
                }

                $oldReference = $locked->source_reference;
                $old = $this->auditValues($locked);
                $locked->update($this->verifiedValues($data, $actor));
                $new = [
                    ...$this->auditValues($locked),
                    'source_reference_changed' => $oldReference !== $locked->source_reference,
                ];
                $this->audit->record('fleet.agency_distance.corrected', $locked, $old, $new);

                return $locked;
            });
        });
    }

    public function setActive(AgencyDistance $distance, User $actor, bool $active): AgencyDistance
    {
        $this->authorize($actor, $distance);

        return DB::transaction(function () use ($active, $actor, $distance): AgencyDistance {
            if ($active) {
                $this->lockActiveAgencies($distance->from_agency_id, $distance->to_agency_id);
            }
            $this->lockPairs([[$distance->from_agency_id, $distance->to_agency_id]]);
            $locked = AgencyDistance::query()->lockForUpdate()->findOrFail($distance->getKey());
            if ($locked->active === $active) {
                return $locked;
            }

            $old = $this->auditValues($locked);
            $locked->update([
                'active' => $active,
                'verified_by_user_id' => $actor->getKey(),
                'verified_at' => now(),
            ]);
            $this->audit->record(
                $active ? 'fleet.agency_distance.activated' : 'fleet.agency_distance.deactivated',
                $locked,
                $old,
                $this->auditValues($locked),
            );

            return $locked;
        });
    }

    private function authorize(User $actor, ?AgencyDistance $distance = null): void
    {
        $allowed = $actor->tenant_id === $this->context->tenantId()
            && $actor->agency_id === null
            && $actor->hasPermission('fleet.distance.manage')
            && ($distance === null || $distance->tenant_id === $actor->tenant_id);

        if (! $allowed) {
            throw new AuthorizationException;
        }
    }

    private function lockActiveAgencies(int $fromId, int $toId): void
    {
        if ($fromId === $toId) {
            throw ValidationException::withMessages([
                'to_agency_id' => 'Les agences de départ et d’arrivée doivent être différentes.',
            ]);
        }

        $agencies = Agency::query()
            ->whereIn('id', [$fromId, $toId])
            ->where('is_active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($agencies->count() !== 2) {
            throw ValidationException::withMessages([
                'from_agency_id' => 'Les deux agences doivent être actives et appartenir à votre entreprise.',
            ]);
        }
    }

    /** @param list<array{0:int,1:int}> $pairs */
    private function lockPairs(array $pairs): void
    {
        $keys = collect($pairs)
            ->map(fn (array $pair): string => implode(':', [
                'agency-distance', $this->context->tenantId(), $pair[0], $pair[1],
            ]))
            ->sort()
            ->values();
        foreach ($keys as $key) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [$key]);
        }
    }

    private function verifiedValues(array $data, User $actor): array
    {
        return [
            'distance_km' => (string) $data['distance_km'],
            'source_type' => AgencyDistanceSourceType::ManualVerified,
            'source_reference' => filled($data['source_reference'] ?? null)
                ? trim((string) $data['source_reference'])
                : null,
            'verified_by_user_id' => $actor->getKey(),
            'verified_at' => now(),
        ];
    }

    private function auditValues(AgencyDistance $distance): array
    {
        return [
            'from_agency_id' => $distance->from_agency_id,
            'to_agency_id' => $distance->to_agency_id,
            'distance_km' => (string) $distance->distance_km,
            'source_type' => $distance->source_type->value,
            'source_reference_present' => filled($distance->source_reference),
            'verified_by_user_id' => $distance->verified_by_user_id,
            'verified_at' => $distance->verified_at?->toIso8601String(),
            'active' => $distance->active,
        ];
    }
}
