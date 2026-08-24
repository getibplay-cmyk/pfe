<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VehicleDamagePredictionRun;

class VehicleDamagePredictionRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('prediction.view');
    }

    public function view(User $user, VehicleDamagePredictionRun $run): bool
    {
        return $this->sameScope($user, $run) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user)
            && $user->hasPermission('prediction.damage.review');
    }

    public function review(User $user, VehicleDamagePredictionRun $run): bool
    {
        return $this->sameScope($user, $run)
            && $this->viewAny($user)
            && $user->hasPermission('prediction.damage.review');
    }

    private function sameScope(User $user, VehicleDamagePredictionRun $run): bool
    {
        return $user->tenant_id === $run->tenant_id
            && ($user->agency_id === null || $user->agency_id === $run->agency_id);
    }
}
