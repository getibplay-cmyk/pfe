<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->is_platform_admin
            && $user->tenant_id !== null
            && $user->hasPermission('role.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $this->viewAny($user)
            && ($role->tenant_id === null || $role->tenant_id === $user->tenant_id);
    }

    public function create(User $user): bool
    {
        return $user->isTenantOwner() && $user->hasPermission('role.manage');
    }

    public function update(User $user, Role $role): bool
    {
        return $this->create($user)
            && ! $role->is_system
            && $role->tenant_id === $user->tenant_id;
    }

    public function delegate(User $user): bool
    {
        return $user->isTenantOwner() && $user->hasPermission('role.delegate');
    }
}
