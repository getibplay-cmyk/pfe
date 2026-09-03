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
            ->subject('Réinitialisation de votre mot de passe — '.config('brand.name'))
            ->greeting('Bonjour '.$notifiable->name.',')
            ->line('Une demande de réinitialisation du mot de passe de votre compte a été reçue.')
            ->action('Réinitialiser mon mot de passe', $url)
            ->line('Ce lien expirera dans '.config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60).' minutes.')
            ->line('Si vous n’êtes pas à l’origine de cette demande, ignorez ce message.');
    }
}
