<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    public function view(User $user, Tenant $tenant): bool
    {
        return ! $user->is_platform_admin && $user->tenant_id === $tenant->getKey();
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $this->view($user, $tenant) && $user->hasPermission('tenant.manage');
    }
}
