<?php

namespace App\Notifications\Auth;

use App\Support\Security\PrivateMailTransport;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyEmailNotification extends VerifyEmail implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 15;

    public array $backoff = [30, 120];

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function shouldSend($notifiable, string $channel): bool
    {
        return $notifiable->is_active && ! $notifiable->hasVerifiedEmail();
    }

    public function toMail($notifiable): MailMessage
    {
        PrivateMailTransport::assertReady();

        return (new MailMessage)
            ->subject(__('Vérifiez votre adresse e-mail — ').config('brand.name'))
            ->greeting(__('Bonjour ').$notifiable->name.',')
            ->line(__('Confirmez votre adresse e-mail professionnelle pour accéder aux fonctions sécurisées de ').config('brand.name').'.')
            ->action(__('Vérifier mon adresse e-mail'), $this->verificationUrl($notifiable))
            ->line(__('Ce lien expirera dans ').config('auth.verification.expire', 60).__(' minutes.'))
            ->line(__('Si vous n’êtes pas à l’origine de cette demande, aucune action n’est nécessaire.'));
    }
}
