<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('customer.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->sameScope($user, $customer) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('customer.create');
    }

    public function update(User $user, Customer $customer): bool
    {
        return ! $customer->trashed() && $this->sameScope($user, $customer) && $user->hasPermission('customer.update');
    }

    public function verify(User $user, Customer $customer): bool
    {
        return $this->update($user, $customer);
    }

    public function archive(User $user, Customer $customer): bool
    {
        return $this->update($user, $customer);
    }

    public function restore(User $user, Customer $customer): bool
    {
        return $customer->trashed() && $this->sameScope($user, $customer) && $user->hasPermission('customer.update');
    }

    public function viewIdentity(User $user, Customer $customer): bool
    {
        return $this->sameScope($user, $customer) && $user->hasPermission('customer.identity.view');
    }

    private function sameScope(User $user, Customer $customer): bool
    {
        return $user->tenant_id === $customer->tenant_id && ($user->agency_id === null || $user->agency_id === $customer->agency_id);
    }
}
