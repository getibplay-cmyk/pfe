<?php

namespace App\Policies;

use App\Models\DemandForecastRun;
use App\Models\User;

class DemandForecastRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('prediction.view');
    }

    public function view(User $user, DemandForecastRun $run): bool
    {
        return $this->viewAny($user)
            && $user->tenant_id === $run->tenant_id
            && ($user->agency_id === null || $user->agency_id === $run->agency_id);
    }
}
