<?php

namespace App\Policies;

use App\Enums\InsurancePolicyStatus;
use App\Models\InsurancePolicy;
use App\Models\User;

class InsurancePolicyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('insurance.view');
    }

    public function view(User $user, InsurancePolicy $policy): bool
    {
        return $this->sameScope($user, $policy) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('insurance.manage');
    }

    public function update(User $user, InsurancePolicy $policy): bool
    {
        return $this->manage($user, $policy) && $policy->status === InsurancePolicyStatus::Draft;
    }

    public function activate(User $user, InsurancePolicy $policy): bool
    {
        return $this->update($user, $policy);
    }

    public function cancel(User $user, InsurancePolicy $policy): bool
    {
        return $this->manage($user, $policy) && in_array($policy->status, [InsurancePolicyStatus::Draft, InsurancePolicyStatus::Active], true);
    }

    public function renew(User $user, InsurancePolicy $policy): bool
    {
        return $this->manage($user, $policy) && $policy->status !== InsurancePolicyStatus::Draft;
    }

    public function uploadDocument(User $user, InsurancePolicy $policy): bool
    {
        return $this->manage($user, $policy) && $user->hasPermission('document.upload');
    }

    private function manage(User $user, InsurancePolicy $policy): bool
    {
        return $this->sameScope($user, $policy) && $user->hasPermission('insurance.manage');
    }

    private function sameScope(User $user, InsurancePolicy $policy): bool
    {
        return $user->tenant_id === $policy->tenant_id && ($user->agency_id === null || $user->agency_id === $policy->agency_id);
    }
}
