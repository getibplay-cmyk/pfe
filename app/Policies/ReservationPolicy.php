<?php

namespace App\Policies;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\User;

class ReservationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('reservation.view');
    }

    public function view(User $user, Reservation $reservation): bool
    {
        return $this->sameScope($user, $reservation) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('reservation.create');
    }

    public function update(User $user, Reservation $reservation): bool
    {
        return $this->sameScope($user, $reservation) && $user->hasPermission('reservation.update') && in_array($reservation->status, [ReservationStatus::Draft, ReservationStatus::Pending], true);
    }

    public function confirm(User $user, Reservation $reservation): bool
    {
        return $this->sameScope($user, $reservation) && $user->hasPermission('reservation.confirm') && $reservation->status->canBeConfirmed();
    }

    public function cancel(User $user, Reservation $reservation): bool
    {
        return $this->sameScope($user, $reservation) && $user->hasPermission('reservation.cancel') && $reservation->status->canBeCancelled();
    }

    private function sameScope(User $user, Reservation $reservation): bool
    {
        return $user->tenant_id === $reservation->tenant_id && ($user->agency_id === null || $user->agency_id === $reservation->agency_id);
    }
}
