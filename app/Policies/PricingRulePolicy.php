<?php

namespace App\Policies;

use App\Models\PricingRule;
use App\Models\User;

class PricingRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('pricing.view');
    }

    public function view(User $user, PricingRule $rule): bool
    {
        return $this->sameScope($user, $rule) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('pricing.manage');
    }

    public function update(User $user, PricingRule $rule): bool
    {
        return $user->tenant_id === $rule->tenant_id
            && ($user->agency_id === null || $user->agency_id === $rule->agency_id)
            && $user->hasPermission('pricing.manage');
    }

    private function sameScope(User $user, PricingRule $rule): bool
    {
        return $user->tenant_id === $rule->tenant_id && ($user->agency_id === null || $rule->agency_id === null || $user->agency_id === $rule->agency_id);
    }
}
