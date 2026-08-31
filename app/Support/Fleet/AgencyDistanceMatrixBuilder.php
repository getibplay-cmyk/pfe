<?php

namespace App\Support\Fleet;

use App\Models\Agency;
use App\Models\AgencyDistance;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use JsonException;

class AgencyDistanceMatrixBuilder
{
    public function __construct(private readonly TenantContext $context) {}

    /** @param Collection<int, Agency> $agencies */
    public function build(Collection $agencies): AgencyDistanceMatrixResult
    {
        $tenantId = $this->context->tenantId();
        $ordered = $agencies->sortBy('id')->values();
        if ($ordered->pluck('id')->unique()->count() !== $ordered->count()) {
            return $this->invalid('duplicate_agency');
        }
        if ($ordered->contains(
            fn (Agency $agency): bool => $agency->tenant_id !== $tenantId || ! $agency->is_active,
        )) {
            return $this->invalid('agency_scope_invalid');
        }

        $agencyIds = $ordered->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $distances = AgencyDistance::query()
            ->where('active', true)
            ->whereIn('from_agency_id', $agencyIds)
            ->whereIn('to_agency_id', $agencyIds)
            ->orderBy('from_agency_id')
            ->orderBy('to_agency_id')
            ->get();

        $matrix = [];
        foreach ($agencyIds as $agencyId) {
            $matrix[$agencyId] = [$agencyId => '0.000'];
        }

        $seen = [];
        foreach ($distances as $distance) {
            $key = $distance->from_agency_id.':'.$distance->to_agency_id;
            if (isset($seen[$key])) {
                return $this->invalid('duplicate_distance');
            }
            $seen[$key] = true;

            $value = $this->validDistance((string) $distance->distance_km);
            if ($value === null || $distance->from_agency_id === $distance->to_agency_id) {
                return $this->invalid('distance_invalid');
            }
            $matrix[$distance->from_agency_id][$distance->to_agency_id] = $value;
        }

        $missing = [];
        foreach ($agencyIds as $originId) {
            foreach ($agencyIds as $destinationId) {
                if ($originId !== $destinationId && ! isset($matrix[$originId][$destinationId])) {
                    $missing[] = [
                        'from_agency_id' => $originId,
                        'to_agency_id' => $destinationId,
                    ];
                }
            }
            ksort($matrix[$originId], SORT_NUMERIC);
        }

        return new AgencyDistanceMatrixResult(
            status: $missing === [] ? 'complete' : 'incomplete',
            matrix: $matrix,
            missingPairs: $missing,
            fingerprint: $this->fingerprint($agencyIds, $matrix, $missing),
        );
    }

    private function invalid(string $reason): AgencyDistanceMatrixResult
    {
        return new AgencyDistanceMatrixResult('invalid', [], [], null, $reason);
    }

    private function validDistance(string $value): ?string
    {
        if (preg_match('/^(?:0|[1-9][0-9]{0,4})\.[0-9]{3}$/D', $value) !== 1) {
            return null;
        }

        [$whole, $fraction] = explode('.', $value);
        $milliKilometres = ((int) $whole * 1000) + (int) $fraction;
        if ($milliKilometres < 1 || $milliKilometres > 10_000_000) {
            return null;
        }

        return sprintf('%d.%03d', (int) $whole, (int) $fraction);
    }

    /**
     * @param  list<int>  $agencyIds
     * @param  array<int, array<int, string>>  $matrix
     * @param  list<array{from_agency_id:int,to_agency_id:int}>  $missing
     *
     * @throws JsonException
     */
    private function fingerprint(array $agencyIds, array $matrix, array $missing): string
    {
        $directions = [];
        foreach ($agencyIds as $originId) {
            foreach ($matrix[$originId] as $destinationId => $distance) {
                $directions[] = [$originId, (int) $destinationId, $distance];
            }
        }

        return hash('sha256', json_encode([
            'schema_version' => '1.0.0',
            'tenant_id' => $this->context->tenantId(),
            'agency_ids' => $agencyIds,
            'directions' => $directions,
            'missing_pairs' => $missing,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
