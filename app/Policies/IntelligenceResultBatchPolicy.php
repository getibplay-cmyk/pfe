<?php

namespace App\Policies;

use App\Models\IntelligenceResultBatch;
use App\Models\User;

class IntelligenceResultBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('prediction.view');
    }

    public function view(User $user, IntelligenceResultBatch $batch): bool
    {
        return $this->sameScope($user, $batch) && $this->viewAny($user);
    }

    public function review(User $user, IntelligenceResultBatch $batch): bool
    {
        return $this->sameScope($user, $batch)
            && $user->hasPermission('prediction.demo.review');
    }

    private function sameScope(User $user, IntelligenceResultBatch $batch): bool
    {
        return $user->tenant_id === $batch->tenant_id
            && ($user->agency_id === null
                || ($batch->agency_id !== null && $user->agency_id === $batch->agency_id));
    }
}
