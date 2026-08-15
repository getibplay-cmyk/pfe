<?php

namespace App\Policies;

use App\Models\DemandHistoryExportRun;
use App\Models\User;

class DemandHistoryExportRunPolicy
{
    public function view(User $user, DemandHistoryExportRun $run): bool
    {
        return ($user->hasPermission('prediction.export')
                || $user->hasPermission('prediction.forecast.import'))
            && $this->sameScope($user, $run);
    }

    public function importForecast(User $user, DemandHistoryExportRun $run): bool
    {
        return $user->hasPermission('prediction.forecast.import') && $this->sameScope($user, $run);
    }

    private function sameScope(User $user, DemandHistoryExportRun $run): bool
    {
        return $user->tenant_id === $run->tenant_id
            && ($user->agency_id === null || $user->agency_id === $run->agency_id);
    }
}
