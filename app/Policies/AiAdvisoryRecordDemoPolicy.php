<?php

namespace App\Policies;

use App\Models\AiAdvisoryRecordDemo;
use App\Models\User;

class AiAdvisoryRecordDemoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('prediction.view');
    }

    public function view(User $user, AiAdvisoryRecordDemo $record): bool
    {
        return $this->sameScope($user, $record) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('prediction.demo.review');
    }

    public function review(User $user, AiAdvisoryRecordDemo $record): bool
    {
        return $this->sameScope($user, $record)
            && $user->hasPermission('prediction.demo.review');
    }

    private function sameScope(User $user, AiAdvisoryRecordDemo $record): bool
    {
        return $user->tenant_id === $record->tenant_id
            && ($user->agency_id === null
                || ($record->agency_id !== null && $user->agency_id === $record->agency_id));
    }
}
