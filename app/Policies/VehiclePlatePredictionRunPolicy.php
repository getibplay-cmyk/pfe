<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VehiclePlatePredictionRun;

class VehiclePlatePredictionRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('prediction.view');
    }

    public function view(User $user, VehiclePlatePredictionRun $run): bool
    {
        return $this->sameScope($user, $run) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user)
            && $user->hasPermission('prediction.plate.review');
    }

    public function review(User $user, VehiclePlatePredictionRun $run): bool
    {
        return $this->sameScope($user, $run)
            && $this->viewAny($user)
            && $user->hasPermission('prediction.plate.review');
    }

    public function viewForVehicleCreation(User $user, VehiclePlatePredictionRun $run): bool
    {
        return $this->sameScope($user, $run)
            && $run->requested_by === $user->id
            && $user->hasPermission('vehicle.create');
    }

    private function sameScope(User $user, VehiclePlatePredictionRun $run): bool
    {
        return $user->tenant_id === $run->tenant_id
            && ($user->agency_id === null || $user->agency_id === $run->agency_id);
    }
}
