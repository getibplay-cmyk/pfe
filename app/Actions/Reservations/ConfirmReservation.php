<?php

namespace App\Actions\Reservations;

use App\Actions\Pricing\CalculateReservationQuote;
use App\Actions\Pricing\ResolvePricingRule;
use App\Enums\ReservationStatus;
use App\Enums\VehicleBlockStatus;
use App\Enums\VehicleBlockType;
use App\Enums\VehicleOperationalStatus;
use App\Enums\VerificationStatus;
use App\Exceptions\VehicleUnavailableException;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Reservation;
use App\Models\ReservationStatusHistory;
use App\Models\Vehicle;
use App\Models\VehicleBlock;
use App\Support\Audit\AuditRecorder;
use App\Support\Reservations\ReservationPeriodValidator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PDOException;

class ConfirmReservation
{
    public function __construct(
        private ResolvePricingRule $resolvePricingRule,
        private CalculateReservationQuote $calculateQuote,
        private AuditRecorder $audit,
        private ReservationPeriodValidator $periods,
    ) {}

    public function handle(Reservation $reservation, int $actorId): Reservation
    {
        try {
            return DB::transaction(function () use ($reservation, $actorId) {
                $locked = Reservation::whereKey($reservation)->lockForUpdate()->firstOrFail();
                if (! $locked->status->canBeConfirmed()) {
                    throw ValidationException::withMessages(['status' => 'Seule une réservation brouillon ou en attente peut être confirmée.']);
                }
                if (! $locked->vehicle_id) {
                    throw ValidationException::withMessages(['vehicle_id' => 'Un véhicule doit être sélectionné avant confirmation.']);
                }
                if (! $locked->driver_id) {
                    throw ValidationException::withMessages(['driver_id' => 'Un conducteur valide doit être sélectionné avant confirmation.']);
                }

                [, $endsAt] = $this->periods->future($locked->starts_at, $locked->ends_at);

                $customer = Customer::withTrashed()->find($locked->customer_id);
                if (! $customer || $customer->trashed()) {
                    throw ValidationException::withMessages(['customer_id' => 'Le client doit être actif.']);
                }
                if ((int) $customer->agency_id !== (int) $locked->agency_id) {
                    throw ValidationException::withMessages(['customer_id' => 'Le client doit appartenir à la même agence que la réservation.']);
                }
                if ($customer->verification_status !== VerificationStatus::Verified) {
                    throw ValidationException::withMessages(['customer_id' => 'Le client doit être vérifié avant confirmation.']);
                }

                $driver = Driver::withTrashed()->where('customer_id', $customer->id)->find($locked->driver_id);
                if (! $driver || $driver->trashed()) {
                    throw ValidationException::withMessages(['driver_id' => 'Le conducteur doit être actif et appartenir au client.']);
                }
                if ($driver->verification_status !== VerificationStatus::Verified) {
                    throw ValidationException::withMessages(['driver_id' => 'Le conducteur doit être vérifié avant confirmation.']);
                }
                if ($driver->licence_expires_at->endOfDay()->lt($endsAt)) {
                    throw ValidationException::withMessages(['driver_id' => 'Le permis du conducteur doit rester valide pendant toute la location.']);
                }
                $vehicle = Vehicle::where('agency_id', $locked->agency_id)
                    ->where('vehicle_category_id', $locked->vehicle_category_id)
                    ->findOrFail($locked->vehicle_id);
                if ($vehicle->operational_status !== VehicleOperationalStatus::Active) {
                    throw ValidationException::withMessages(['vehicle_id' => 'Seul un véhicule opérationnel actif peut être réservé.']);
                }

                $rule = $this->resolvePricingRule->handle($locked->agency_id, $locked->vehicle_category_id, $locked->starts_at);
                $quote = $this->calculateQuote->handle($rule, $locked->starts_at, $locked->ends_at, $locked->options_total);
                VehicleBlock::create([
                    'agency_id' => $locked->agency_id,
                    'vehicle_id' => $vehicle->id,
                    'reservation_id' => $locked->id,
                    'block_type' => VehicleBlockType::Reservation,
                    'starts_at' => $locked->starts_at,
                    'ends_at' => $locked->ends_at,
                    'status' => VehicleBlockStatus::Active,
                    'reason' => 'Réservation '.$locked->reservation_number,
                    'created_by' => $actorId,
                ]);

                $from = $locked->status;
                $locked->forceFill([...$quote, 'status' => ReservationStatus::Confirmed, 'confirmed_at' => now(), 'expires_at' => null])->save();
                ReservationStatusHistory::create(['reservation_id' => $locked->id, 'from_status' => $from, 'to_status' => ReservationStatus::Confirmed, 'changed_by' => $actorId]);
                $this->audit->record('reservation.confirmed', $locked, ['status' => $from->value], ['status' => ReservationStatus::Confirmed->value, 'total_amount' => $quote['total_amount'], 'currency' => $quote['currency']]);

                return $locked->refresh();
            });
        } catch (QueryException $exception) {
            if ($this->isExclusionViolation($exception)) {
                throw new VehicleUnavailableException;
            }

            throw $exception;
        }
    }

    private function isExclusionViolation(QueryException $exception): bool
    {
        $previous = $exception->getPrevious();

        return $exception->getCode() === '23P01'
            || ($previous instanceof PDOException && ($previous->errorInfo[0] ?? null) === '23P01')
            || $previous?->getCode() === '23P01';
    }
}
