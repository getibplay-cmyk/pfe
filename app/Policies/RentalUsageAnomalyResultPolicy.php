<?php

namespace App\Policies;

use App\Models\RentalUsageAnomalyResult;
use App\Models\User;

class RentalUsageAnomalyResultPolicy
{
    public function review(User $user, RentalUsageAnomalyResult $result): bool
    {
        return $user->hasPermission('prediction.view')
            && $user->hasPermission('prediction.anomaly.review')
            && $user->tenant_id === $result->tenant_id
            && ($user->agency_id === null || $user->agency_id === $result->agency_id);
    }
}
