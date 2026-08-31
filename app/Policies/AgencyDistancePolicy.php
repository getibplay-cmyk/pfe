<?php

namespace App\Policies;

use App\Models\AgencyDistance;
use App\Models\User;

class AgencyDistancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null
            && ($user->hasPermission('fleet.distance.view')
                || $user->hasPermission('fleet.distance.manage'));
    }

    public function view(User $user, AgencyDistance $distance): bool
    {
        return $user->tenant_id === $distance->tenant_id && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->tenant_id !== null
            && $user->agency_id === null
            && $user->hasPermission('fleet.distance.manage');
    }

    public function update(User $user, AgencyDistance $distance): bool
    {
        return $user->tenant_id === $distance->tenant_id && $this->create($user);
    }
}
