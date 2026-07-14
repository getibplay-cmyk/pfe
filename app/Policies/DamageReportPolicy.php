<?php

namespace App\Policies;

use App\Models\DamageReport;
use App\Models\User;

class DamageReportPolicy
{
    public function view(User $user, DamageReport $damage): bool
    {
        return $this->sameScope($user, $damage) && $user->hasPermission('damage.view');
    }

    public function report(User $user, DamageReport $damage): bool
    {
        return $this->sameScope($user, $damage) && $user->hasPermission('damage.report');
    }

    public function review(User $user, DamageReport $damage): bool
    {
        return $this->sameScope($user, $damage) && $user->hasPermission('damage.review');
    }

    private function sameScope(User $user, DamageReport $damage): bool
    {
        return $user->tenant_id === $damage->tenant_id && ($user->agency_id === null || $user->agency_id === $damage->agency_id);
    }
}
