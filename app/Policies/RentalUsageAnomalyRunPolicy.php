<?php

namespace App\Policies;

use App\Models\RentalUsageAnomalyRun;
use App\Models\User;

class RentalUsageAnomalyRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('prediction.view');
    }

    public function view(User $user, RentalUsageAnomalyRun $run): bool
    {
        return $this->viewAny($user)
            && $user->tenant_id === $run->tenant_id
            && ($user->agency_id === null || $user->agency_id === $run->agency_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->hasPermission('prediction.anomaly.review');
    }
}
