<?php

namespace App\Actions\VehicleBlocks;

use App\Enums\VehicleBlockStatus;
use App\Enums\VehicleBlockType;
use App\Enums\VehicleOperationalStatus;
use App\Models\Vehicle;
use App\Models\VehicleBlock;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\AgencyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateManualVehicleBlock
{
    public function __construct(
        private AgencyAccess $agencies,
        private AuditRecorder $audit,
    ) {}

    public function handle(array $data, int $actorId): VehicleBlock
    {
        $agencyId = $this->agencies->required($data['agency_id'] ?? null);
        $vehicle = Vehicle::query()
            ->whereKey($data['vehicle_id'] ?? null)
            ->where('agency_id', $agencyId)
            ->where('operational_status', VehicleOperationalStatus::Active)
            ->first();

        if (! $vehicle) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Le véhicule doit être actif et appartenir à l’agence sélectionnée.',
            ]);
        }

        $startsAt = CarbonImmutable::parse($data['starts_at']);
        $endsAt = CarbonImmutable::parse($data['ends_at']);
        $reason = trim((string) ($data['reason'] ?? ''));

        if (! $startsAt->lessThan($endsAt)) {
            throw ValidationException::withMessages(['ends_at' => 'La fin doit être strictement postérieure au début.']);
        }

        if (! $endsAt->isFuture()) {
            throw ValidationException::withMessages(['ends_at' => 'La fin du bloc doit être future.']);
        }

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Le motif du bloc manuel est obligatoire.']);
        }

        try {
            return DB::transaction(function () use ($agencyId, $vehicle, $startsAt, $endsAt, $reason, $actorId) {
                $block = VehicleBlock::create([
                    'agency_id' => $agencyId,
                    'vehicle_id' => $vehicle->id,
                    'reservation_id' => null,
                    'rental_contract_id' => null,
                    'maintenance_order_id' => null,
                    'block_type' => VehicleBlockType::Manual,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'status' => VehicleBlockStatus::Active,
                    'reason' => $reason,
                    'created_by' => $actorId,
                    'released_at' => null,
                ]);

                $this->audit->record('vehicle_block.manual.created', $block, [], [
                    'vehicle_id' => $vehicle->id,
                    'starts_at' => $startsAt->toIso8601String(),
                    'ends_at' => $endsAt->toIso8601String(),
                ]);

                return $block->refresh();
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23P01') {
                throw ValidationException::withMessages([
                    'vehicle_id' => 'Ce véhicule est déjà bloqué sur tout ou partie de cette période.',
                ]);
            }

            if (str_starts_with((string) $exception->getCode(), '23')) {
                throw ValidationException::withMessages([
                    'vehicle_block' => 'Le bloc manuel ne respecte pas les contraintes de disponibilité.',
                ]);
            }

            throw $exception;
        }
    }
}
