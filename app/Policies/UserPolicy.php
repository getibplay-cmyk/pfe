<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('user.view') || $user->hasPermission('user.manage');
    }

    public function view(User $user, User $subject): bool
    {
        return $this->sameScope($user, $subject) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('user.manage');
    }

    public function update(User $user, User $subject): bool
    {
        return $user->getKey() !== $subject->getKey()
            && $this->sameScope($user, $subject)
            && $user->hasPermission('user.manage');
    }

    private function sameScope(User $user, User $subject): bool
    {
        return $user->tenant_id === $subject->tenant_id
            && ($user->agency_id === null || $user->agency_id === $subject->agency_id);
    }
}
