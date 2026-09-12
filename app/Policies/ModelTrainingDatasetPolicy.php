<?php

namespace App\Policies;

use App\Models\ModelTrainingDataset;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

class ModelTrainingDatasetPolicy
{
    public function create(User $user): bool
    {
        return $user->is_active && ! $user->is_platform_admin && $user->isTenantOwner()
            && $user->hasPermission('prediction.export')
            && $user->tenant_id === app(TenantContext::class)->tenantId();
    }

    public function view(User $user, ModelTrainingDataset $dataset): bool
    {
        return $this->create($user) && $dataset->tenant_id === $user->tenant_id;
    }

    public function share(User $user, ModelTrainingDataset $dataset): bool
    {
        return $this->view($user, $dataset);
    }

    public function revoke(User $user, ModelTrainingDataset $dataset): bool
    {
        return $this->view($user, $dataset);
    }
}
