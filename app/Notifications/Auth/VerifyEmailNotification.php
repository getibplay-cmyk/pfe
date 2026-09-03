<?php

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyEmailNotification extends VerifyEmail
{
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Vérifiez votre adresse e-mail — '.config('brand.name'))
            ->greeting('Bonjour '.$notifiable->name.',')
            ->line('Confirmez votre adresse e-mail professionnelle pour accéder aux fonctions sécurisées de '.config('brand.name').'.')
            ->action('Vérifier mon adresse e-mail', $this->verificationUrl($notifiable))
            ->line('Ce lien expirera dans '.config('auth.verification.expire', 60).' minutes.')
            ->line('Si vous n’êtes pas à l’origine de cette demande, aucune action n’est nécessaire.');
    }
}
