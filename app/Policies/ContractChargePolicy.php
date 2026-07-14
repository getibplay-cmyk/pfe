<?php

namespace App\Policies;

use App\Models\ContractCharge;
use App\Models\User;

class ContractChargePolicy
{
    public function review(User $user, ContractCharge $charge): bool
    {
        $contract = $charge->rentalContract;

        return $user->tenant_id === $charge->tenant_id && ($user->agency_id === null || $user->agency_id === $contract->agency_id) && $user->hasPermission('charge.review');
    }
}
