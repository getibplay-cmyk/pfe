<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Support\Audit\AuditRecorder;
use App\Support\Reservations\ReservationPeriodValidator;
use App\Support\Reservations\ReservationRelationships;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateDraftReservation
{
    public function __construct(
        private AuditRecorder $audit,
        private ReservationPeriodValidator $periods,
        private ReservationRelationships $relationships,
    ) {}

    public function handle(Reservation $reservation, array $data): Reservation
    {
        return DB::transaction(function () use ($reservation, $data) {
            $locked = Reservation::whereKey($reservation)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [ReservationStatus::Draft, ReservationStatus::Pending], true)) {
                throw ValidationException::withMessages(['status' => 'Seule une réservation brouillon ou en attente peut être modifiée.']);
            }

            [$startsAt, $endsAt] = $this->periods->future($data['starts_at'], $data['ends_at']);
            $related = $this->relationships->resolve($data);
            $old = $locked->only(['agency_id', 'customer_id', 'driver_id', 'vehicle_category_id', 'vehicle_id', 'starts_at', 'ends_at']);
            $locked->update([
                'agency_id' => $related['agencyId'],
                'customer_id' => $related['customer']->id,
                'driver_id' => $related['driver']?->id,
                'vehicle_category_id' => $related['category']->id,
                'vehicle_id' => $related['vehicle']?->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => $data['status'] ?? $locked->status,
                'expires_at' => $data['expires_at'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $this->audit->record('reservation.updated', $locked, $old, $locked->only(array_keys($old)));

            return $locked->refresh();
        });
    }
}
