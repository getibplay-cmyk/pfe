<?php

namespace App\Policies;

use App\Models\IntelligenceDatasetExportRun;
use App\Models\User;

class IntelligenceDatasetExportRunPolicy
{
    public function view(User $user, IntelligenceDatasetExportRun $run): bool
    {
        return $user->hasPermission('prediction.export')
            && $user->tenant_id === $run->tenant_id
            && ($user->agency_id === null || $user->agency_id === $run->agency_id);
    }
}
