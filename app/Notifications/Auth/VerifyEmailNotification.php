<?php

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyEmailNotification extends VerifyEmail
{
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Vérifiez votre adresse e-mail — ').config('brand.name'))
            ->greeting(__('Bonjour ').$notifiable->name.',')
            ->line(__('Confirmez votre adresse e-mail professionnelle pour accéder aux fonctions sécurisées de ').config('brand.name').'.')
            ->action(__('Vérifier mon adresse e-mail'), $this->verificationUrl($notifiable))
            ->line(__('Ce lien expirera dans ').config('auth.verification.expire', 60).__(' minutes.'))
            ->line(__('Si vous n’êtes pas à l’origine de cette demande, aucune action n’est nécessaire.'));
    }
}
