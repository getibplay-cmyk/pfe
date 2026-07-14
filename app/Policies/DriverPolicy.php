<?php

namespace App\Policies;

use App\Models\Driver;
use App\Models\User;

class DriverPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('customer.update');
    }

    public function view(User $user, Driver $driver): bool
    {
        return $user->tenant_id === $driver->tenant_id && (! $user->isAgencyManager() || $user->agency_id === $driver->customer?->agency_id) && $user->hasPermission('customer.view');
    }
}
