<?php

namespace App\Policies;

use App\Models\FleetReallocationPlanningRun;
use App\Models\User;

class FleetReallocationPlanningRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->operationalPlanner($user);
    }

    public function create(User $user): bool
    {
        return $this->operationalPlanner($user);
    }

    public function view(User $user, FleetReallocationPlanningRun $run): bool
    {
        return $run->tenant_id === $user->tenant_id && $this->operationalPlanner($user);
    }

    private function operationalPlanner(User $user): bool
    {
        return $user->is_active
            && ! $user->is_platform_admin
            && in_array($user->role?->slug, ['tenant-owner', 'fleet-manager'], true)
            && $user->hasPermission('prediction.demo.review');
    }
}
