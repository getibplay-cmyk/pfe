<?php

namespace App\Policies;

use App\Models\Agency;
use App\Models\User;

class AgencyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('agency.view') || $user->hasPermission('agency.manage');
    }

    public function view(User $user, Agency $agency): bool
    {
        return $this->sameScope($user, $agency) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return ! $user->isAgencyManager() && $user->hasPermission('agency.manage');
    }

    public function update(User $user, Agency $agency): bool
    {
        return $this->sameScope($user, $agency) && $user->hasPermission('agency.manage');
    }

    public function delete(User $user, Agency $agency): bool
    {
        return $this->update($user, $agency) && ! $user->isAgencyManager();
    }

    private function sameScope(User $user, Agency $agency): bool
    {
        return $user->tenant_id === $agency->tenant_id
            && (! $user->isAgencyManager() || $user->agency_id === $agency->getKey());
    }
}
