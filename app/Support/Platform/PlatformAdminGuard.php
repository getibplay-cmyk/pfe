<?php

namespace App\Support\Platform;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class PlatformAdminGuard
{
    public function actor(int $actorId): User
    {
        $actor = User::query()->find($actorId);

        if ($actor === null
            || ! $actor->is_active
            || ! $actor->is_platform_admin
            || $actor->tenant_id !== null) {
            throw new AuthorizationException;
        }

        return $actor;
    }
}
