<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;

class VehiclePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('vehicle.view');
    }

    public function view(User $user, Vehicle $vehicle): bool
    {
        return $this->sameScope($user, $vehicle) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('vehicle.create');
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return $this->sameScope($user, $vehicle) && $user->hasPermission('vehicle.update');
    }

    public function delete(User $user, Vehicle $vehicle): bool
    {
        return $this->sameScope($user, $vehicle) && $user->hasPermission('vehicle.archive');
    }

    private function sameScope(User $user, Vehicle $vehicle): bool
    {
        return $user->tenant_id === $vehicle->tenant_id && (! $user->isAgencyManager() || $user->agency_id === $vehicle->agency_id);
    }
}
