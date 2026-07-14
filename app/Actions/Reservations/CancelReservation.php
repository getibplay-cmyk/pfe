<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Enums\VehicleBlockStatus;
use App\Models\Reservation;
use App\Models\ReservationStatusHistory;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelReservation
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(Reservation $reservation, string $reason, int $actorId): Reservation
    {
        return DB::transaction(function () use ($reservation, $reason, $actorId) {
            $locked = Reservation::whereKey($reservation)->lockForUpdate()->firstOrFail();
            if (! $locked->status->canBeCancelled()) {
                throw ValidationException::withMessages(['status' => 'Cette réservation ne peut plus être annulée.']);
            }
            $from = $locked->status;
            $locked->vehicleBlocks()->where('status', VehicleBlockStatus::Active)->update(['status' => VehicleBlockStatus::Released->value, 'released_at' => now(), 'updated_at' => now()]);
            $locked->forceFill(['status' => ReservationStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => $reason, 'expires_at' => null])->save();
            ReservationStatusHistory::create(['reservation_id' => $locked->id, 'from_status' => $from, 'to_status' => ReservationStatus::Cancelled, 'reason' => $reason, 'changed_by' => $actorId]);
            $this->audit->record('reservation.cancelled', $locked, ['status' => $from->value], ['status' => ReservationStatus::Cancelled->value, 'reason' => $reason]);

            return $locked->refresh();
        });
    }
}
