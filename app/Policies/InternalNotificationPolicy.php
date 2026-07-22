<?php

namespace App\Policies;

use App\Models\InternalNotification;
use App\Models\User;
use App\Support\Notifications\NotificationInbox;

class InternalNotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->is_platform_admin && $user->tenant_id !== null;
    }

    public function view(User $user, InternalNotification $notification): bool
    {
        return app(NotificationInbox::class)->owns($user, $notification);
    }

    public function update(User $user, InternalNotification $notification): bool
    {
        return $this->view($user, $notification);
    }
}
