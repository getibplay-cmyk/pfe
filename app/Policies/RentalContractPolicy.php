<?php

namespace App\Policies;

use App\Enums\RentalContractStatus;
use App\Models\RentalContract;
use App\Models\User;

class RentalContractPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('contract.view');
    }

    public function view(User $user, RentalContract $contract): bool
    {
        return $this->sameScope($user, $contract) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('contract.create');
    }

    public function version(User $user, RentalContract $contract): bool
    {
        return $this->sameScope($user, $contract) && $user->hasPermission('contract.version') && in_array($contract->status, [RentalContractStatus::Draft, RentalContractStatus::Ready, RentalContractStatus::Accepted], true);
    }

    public function accept(User $user, RentalContract $contract): bool
    {
        return $this->sameScope($user, $contract) && $user->hasPermission('contract.accept') && $contract->status === RentalContractStatus::Ready;
    }

    public function activate(User $user, RentalContract $contract): bool
    {
        return $this->sameScope($user, $contract) && $user->hasPermission('contract.activate') && $contract->status === RentalContractStatus::Accepted;
    }

    public function return(User $user, RentalContract $contract): bool
    {
        return $this->sameScope($user, $contract) && $user->hasPermission('contract.return') && in_array($contract->status, [RentalContractStatus::Active, RentalContractStatus::ReturnPending], true);
    }

    public function cancel(User $user, RentalContract $contract): bool
    {
        return $this->sameScope($user, $contract) && $user->hasPermission('contract.cancel') && in_array($contract->status, [RentalContractStatus::Draft, RentalContractStatus::Ready], true);
    }

    private function sameScope(User $user, RentalContract $contract): bool
    {
        return $user->tenant_id === $contract->tenant_id && ($user->agency_id === null || $user->agency_id === $contract->agency_id);
    }
}
