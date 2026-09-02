<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class VerificationNotificationSender
{
    public function send(User $user): bool
    {
        if ($user->hasVerifiedEmail()) {
            return true;
        }

        try {
            $user->sendEmailVerificationNotification();

            return true;
        } catch (Throwable $exception) {
            report($exception);
            Log::warning('Email verification notification could not be delivered.', [
                'event' => 'auth.email_verification.delivery_failed',
                'user_id' => $user->getKey(),
                'tenant_id' => $user->tenant_id,
            ]);

            return false;
        }
    }
}
