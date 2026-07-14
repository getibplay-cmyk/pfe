<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VehicleCategory;

class VehicleCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('vehicle.view');
    }

    public function view(User $user, VehicleCategory $category): bool
    {
        return $user->tenant_id === $category->tenant_id && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('vehicle.create');
    }

    public function update(User $user, VehicleCategory $category): bool
    {
        return $this->view($user, $category) && $user->hasPermission('vehicle.update');
    }

    public function delete(User $user, VehicleCategory $category): bool
    {
        return $this->view($user, $category) && $user->hasPermission('vehicle.archive');
    }
}
