<?php

namespace App\Notifications\Auth;

use App\Support\Security\PrivateMailTransport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class EmailChangeNotification extends Notification implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 15;

    public array $backoff = [30, 120];

    public function __construct(private readonly ?string $verificationUrl = null)
    {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        PrivateMailTransport::assertReady();
        $mail = (new MailMessage)->subject(__('Modification de votre adresse e-mail').' — '.config('brand.name'));
        if ($this->verificationUrl !== null) {
            return $mail->line(__('Confirmez cette nouvelle adresse pour l’associer à votre compte. Votre adresse actuelle reste valable jusque-là.'))
                ->action(__('Confirmer la nouvelle adresse'), $this->verificationUrl)
                ->line(__('Ce lien expire dans 30 minutes. Une confirmation dans votre compte est nécessaire.'));
        }

        return $mail->line(__('Une modification de votre adresse e-mail a été demandée.'))
            ->line(__('Si vous n’êtes pas à l’origine de cette demande, connectez-vous, annulez la demande et changez votre mot de passe.'));
    }
}
