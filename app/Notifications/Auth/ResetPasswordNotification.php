<?php

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        return (new MailMessage)
            ->subject(__('Réinitialisation de votre mot de passe — ').config('brand.name'))
            ->greeting(__('Bonjour ').$notifiable->name.',')
            ->line(__('Une demande de réinitialisation du mot de passe de votre compte a été reçue.'))
            ->action(__('Réinitialiser mon mot de passe'), $url)
            ->line(__('Ce lien expirera dans ').config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60).__(' minutes.'))
            ->line(__('Si vous n’êtes pas à l’origine de cette demande, ignorez ce message.'));
    }
}
