<?php

namespace App\Policies;

use App\Models\User;

class ModelTrainingCampaignPolicy
{
    public function create(User $user): bool
    {
        return $user->is_active && $user->is_platform_admin && $user->tenant_id === null;
    }
}
