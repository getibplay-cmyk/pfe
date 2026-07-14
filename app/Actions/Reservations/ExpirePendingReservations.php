<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\ReservationStatusHistory;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class ExpirePendingReservations
{
    public function handle(): int
    {
        $expired = 0;
        Reservation::withoutGlobalScopes()->where('status', ReservationStatus::Pending)->whereNotNull('expires_at')->where('expires_at', '<=', now())->orderBy('id')->each(function (Reservation $reservation) use (&$expired) {
            $tenant = Tenant::find($reservation->tenant_id);
            if (! $tenant) {
                return;
            }
            app(TenantContext::class)->run($tenant, function () use ($reservation, &$expired) {
                DB::transaction(function () use ($reservation, &$expired) {
                    $locked = Reservation::whereKey($reservation->id)->lockForUpdate()->first();
                    if (! $locked || $locked->status !== ReservationStatus::Pending || ! $locked->expires_at?->isPast()) {
                        return;
                    }
                    $locked->forceFill(['status' => ReservationStatus::Expired])->save();
                    ReservationStatusHistory::create(['reservation_id' => $locked->id, 'from_status' => ReservationStatus::Pending, 'to_status' => ReservationStatus::Expired, 'reason' => 'Expiration automatique', 'changed_by' => null]);
                    $expired++;
                });
            });
        });

        return $expired;
    }
}
