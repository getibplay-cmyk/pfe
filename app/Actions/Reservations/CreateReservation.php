<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Reservation;
use App\Models\ReservationStatusHistory;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Support\Audit\AuditRecorder;
use App\Support\Reservations\ReservationPeriodValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateReservation
{
    public function __construct(
        private GenerateReservationNumber $numbers,
        private AuditRecorder $audit,
        private ReservationPeriodValidator $periods,
    ) {}

    public function handle(array $data, int $actorId): Reservation
    {
        return DB::transaction(function () use ($data, $actorId) {
            [$startsAt, $endsAt] = $this->periods->future($data['starts_at'], $data['ends_at']);

            $agency = Agency::findOrFail($data['agency_id']);
            $customer = Customer::findOrFail($data['customer_id']);
            $category = VehicleCategory::findOrFail($data['vehicle_category_id']);
            $driver = isset($data['driver_id']) ? Driver::where('customer_id', $customer->id)->findOrFail($data['driver_id']) : null;
            $vehicle = isset($data['vehicle_id']) ? Vehicle::where('agency_id', $agency->id)->where('vehicle_category_id', $category->id)->findOrFail($data['vehicle_id']) : null;
            $status = ReservationStatus::tryFrom($data['status'] ?? 'draft');
            if (! in_array($status, [ReservationStatus::Draft, ReservationStatus::Pending], true)) {
                throw ValidationException::withMessages(['status' => 'Une réservation doit être créée en brouillon ou en attente.']);
            }

            $reservation = Reservation::create([
                'agency_id' => $agency->id,
                'customer_id' => $customer->id,
                'driver_id' => $driver?->id,
                'vehicle_category_id' => $category->id,
                'vehicle_id' => $vehicle?->id,
                'reservation_number' => $this->numbers->handle($startsAt->year),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => $status,
                'notes' => $data['notes'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'created_by' => $actorId,
            ]);
            ReservationStatusHistory::create(['reservation_id' => $reservation->id, 'from_status' => null, 'to_status' => $status, 'changed_by' => $actorId]);
            $this->audit->record('reservation.created', $reservation, [], ['status' => $status->value, 'reservation_number' => $reservation->reservation_number]);

            return $reservation;
        });
    }
}
