<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VehicleBlock;

class VehicleBlockPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('reservation.view') || $user->hasPermission('vehicle_block.manage');
    }

    public function view(User $user, VehicleBlock $block): bool
    {
        return $user->tenant_id === $block->tenant_id && ($user->agency_id === null || $user->agency_id === $block->agency_id) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('vehicle_block.manage');
    }

    public function update(User $user, VehicleBlock $block): bool
    {
        return $this->view($user, $block) && $user->hasPermission('vehicle_block.manage');
    }
}
