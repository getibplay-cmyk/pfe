<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VehicleInspection;

class VehicleInspectionPolicy
{
    public function manage(User $user, VehicleInspection $inspection): bool
    {
        return $user->tenant_id === $inspection->tenant_id && ($user->agency_id === null || $user->agency_id === $inspection->agency_id) && $user->hasPermission('inspection.manage');
    }
}
