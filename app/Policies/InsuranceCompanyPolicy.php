<?php

namespace App\Policies;

use App\Models\InsuranceCompany;
use App\Models\User;

class InsuranceCompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('insurance.view');
    }

    public function view(User $user, InsuranceCompany $company): bool
    {
        return $user->tenant_id === $company->tenant_id && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('insurance.manage');
    }

    public function update(User $user, InsuranceCompany $company): bool
    {
        return $this->view($user, $company) && $user->hasPermission('insurance.manage');
    }

    public function changeState(User $user, InsuranceCompany $company): bool
    {
        return $this->update($user, $company);
    }
}
