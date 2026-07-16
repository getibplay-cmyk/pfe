<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\ReservationStatusHistory;
use App\Support\Audit\AuditRecorder;
use App\Support\Reservations\ReservationPeriodValidator;
use App\Support\Reservations\ReservationRelationships;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateReservation
{
    public function __construct(
        private GenerateReservationNumber $numbers,
        private AuditRecorder $audit,
        private ReservationPeriodValidator $periods,
        private ReservationRelationships $relationships,
    ) {}

    public function handle(array $data, int $actorId): Reservation
    {
        return DB::transaction(function () use ($data, $actorId) {
            [$startsAt, $endsAt] = $this->periods->future($data['starts_at'], $data['ends_at']);
            $related = $this->relationships->resolve($data);
            $status = ReservationStatus::tryFrom($data['status'] ?? 'draft');
            if (! in_array($status, [ReservationStatus::Draft, ReservationStatus::Pending], true)) {
                throw ValidationException::withMessages(['status' => 'Une réservation doit être créée en brouillon ou en attente.']);
            }

            $reservation = Reservation::create([
                'agency_id' => $related['agencyId'],
                'customer_id' => $related['customer']->id,
                'driver_id' => $related['driver']?->id,
                'vehicle_category_id' => $related['category']->id,
                'vehicle_id' => $related['vehicle']?->id,
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
