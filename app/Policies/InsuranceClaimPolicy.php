<?php

namespace App\Policies;

use App\Models\InsuranceClaim;
use App\Models\User;

class InsuranceClaimPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('claim.view');
    }

    public function view(User $user, InsuranceClaim $claim): bool
    {
        return $this->sameScope($user, $claim) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('claim.manage');
    }

    public function manage(User $user, InsuranceClaim $claim): bool
    {
        return $this->sameScope($user, $claim) && $user->hasPermission('claim.manage');
    }

    public function uploadDocument(User $user, InsuranceClaim $claim): bool
    {
        return $this->manage($user, $claim) && $user->hasPermission('document.upload');
    }

    private function sameScope(User $user, InsuranceClaim $claim): bool
    {
        return $user->tenant_id === $claim->tenant_id && ($user->agency_id === null || $user->agency_id === $claim->agency_id);
    }
}
