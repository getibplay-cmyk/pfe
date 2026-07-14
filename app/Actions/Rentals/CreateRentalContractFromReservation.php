<?php

namespace App\Actions\Rentals;

use App\Enums\RentalContractStatus;
use App\Enums\ReservationStatus;
use App\Enums\VehicleBlockStatus;
use App\Enums\VehicleBlockType;
use App\Models\ContractDriver;
use App\Models\ContractStatusHistory;
use App\Models\RentalContract;
use App\Models\Reservation;
use App\Models\ReservationStatusHistory;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateRentalContractFromReservation
{
    public function __construct(private GenerateBusinessNumber $numbers, private CreateContractVersion $versions, private AuditRecorder $audit) {}

    public function handle(Reservation $reservation, int $actorId): RentalContract
    {
        return DB::transaction(function () use ($reservation, $actorId) {
            $locked = Reservation::with(['customer', 'driver', 'vehicle', 'activeVehicleBlock'])->whereKey($reservation)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ReservationStatus::Confirmed) {
                throw ValidationException::withMessages(['reservation' => 'Un contrat exige une réservation confirmée.']);
            }
            if (! $locked->driver || ! $locked->vehicle || empty($locked->pricing_snapshot)) {
                throw ValidationException::withMessages(['reservation' => 'La réservation confirmée est incomplète.']);
            }
            if ($locked->rentalContract()->where('status', '!=', 'cancelled')->exists()) {
                throw ValidationException::withMessages(['reservation' => 'Cette réservation possède déjà un contrat actif.']);
            }
            $block = $locked->activeVehicleBlock;
            if (! $block || $block->block_type !== VehicleBlockType::Reservation || $block->status !== VehicleBlockStatus::Active) {
                throw ValidationException::withMessages(['vehicle_block' => 'Le bloc actif de la réservation est introuvable.']);
            }

            $contract = RentalContract::create([
                'agency_id' => $locked->agency_id, 'reservation_id' => $locked->id, 'customer_id' => $locked->customer_id, 'vehicle_id' => $locked->vehicle_id,
                'contract_number' => $this->numbers->handle('contract', $locked->starts_at->year), 'status' => RentalContractStatus::Draft,
                'expected_start_at' => $locked->starts_at, 'expected_return_at' => $locked->ends_at,
                'rental_subtotal' => $locked->total_amount, 'additional_charges_total' => '0.00', 'total_amount' => $locked->total_amount,
                'deposit_required' => $locked->deposit_amount, 'currency' => $locked->currency, 'created_by' => $actorId,
            ]);
            ContractDriver::create(['rental_contract_id' => $contract->id, 'customer_id' => $locked->customer_id, 'driver_id' => $locked->driver_id, 'is_primary' => true, 'authorization_snapshot' => ['driver_id' => $locked->driver_id, 'licence_expires_at' => $locked->driver->licence_expires_at->toDateString()]]);
            $this->versions->handle($contract, $actorId, 'Version initiale depuis réservation confirmée');
            $block->update(['rental_contract_id' => $contract->id, 'block_type' => VehicleBlockType::Contract]);
            $locked->forceFill(['status' => ReservationStatus::Converted])->save();
            ReservationStatusHistory::create(['reservation_id' => $locked->id, 'from_status' => ReservationStatus::Confirmed, 'to_status' => ReservationStatus::Converted, 'reason' => 'Contrat '.$contract->contract_number, 'changed_by' => $actorId]);
            ContractStatusHistory::create(['rental_contract_id' => $contract->id, 'from_status' => null, 'to_status' => RentalContractStatus::Draft, 'changed_by' => $actorId]);
            $this->audit->record('contract.created', $contract, [], ['contract_number' => $contract->contract_number, 'reservation_id' => $locked->id, 'version_id' => $contract->current_version_id]);

            return $contract->refresh();
        });
    }
}
