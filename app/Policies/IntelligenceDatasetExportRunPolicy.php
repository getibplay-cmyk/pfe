<?php

namespace App\Policies;

use App\Models\IntelligenceDatasetExportRun;
use App\Models\User;

class IntelligenceDatasetExportRunPolicy
{
    public function view(User $user, IntelligenceDatasetExportRun $run): bool
    {
        return $user->hasPermission('prediction.export')
            && $this->sameScope($user, $run);
    }

    public function importResultBatch(User $user, IntelligenceDatasetExportRun $run): bool
    {
        return $user->hasPermission('prediction.demo.review')
            && $this->sameScope($user, $run);
    }

    private function sameScope(User $user, IntelligenceDatasetExportRun $run): bool
    {
        return $user->tenant_id === $run->tenant_id
            && ($user->agency_id === null
                || ($run->agency_id !== null && $user->agency_id === $run->agency_id));
    }
}
