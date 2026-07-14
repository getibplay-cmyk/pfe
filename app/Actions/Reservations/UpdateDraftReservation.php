<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Support\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateDraftReservation
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(Reservation $reservation, array $data): Reservation
    {
        return DB::transaction(function () use ($reservation, $data) {
            $locked = Reservation::whereKey($reservation)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [ReservationStatus::Draft, ReservationStatus::Pending], true)) {
                throw ValidationException::withMessages(['status' => 'Seule une réservation brouillon ou en attente peut être modifiée.']);
            }
            $startsAt = CarbonImmutable::parse($data['starts_at']);
            $endsAt = CarbonImmutable::parse($data['ends_at']);
            if ($endsAt->lte($startsAt)) {
                throw ValidationException::withMessages(['ends_at' => 'La fin doit être strictement postérieure au début.']);
            }
            $agency = Agency::findOrFail($data['agency_id']);
            $customer = Customer::findOrFail($data['customer_id']);
            $category = VehicleCategory::findOrFail($data['vehicle_category_id']);
            if (! empty($data['driver_id'])) {
                Driver::where('customer_id', $customer->id)->findOrFail($data['driver_id']);
            }
            if (! empty($data['vehicle_id'])) {
                Vehicle::where('agency_id', $agency->id)->where('vehicle_category_id', $category->id)->findOrFail($data['vehicle_id']);
            }
            $old = $locked->only(['agency_id', 'customer_id', 'driver_id', 'vehicle_category_id', 'vehicle_id', 'starts_at', 'ends_at']);
            $locked->update($data);
            $this->audit->record('reservation.updated', $locked, $old, $locked->only(array_keys($old)));

            return $locked->refresh();
        });
    }
}
