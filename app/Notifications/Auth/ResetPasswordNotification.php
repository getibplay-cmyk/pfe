<?php

namespace App\Notifications\Auth;

use App\Support\Security\PrivateMailTransport;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Password;

class ResetPasswordNotification extends ResetPassword implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 15;

    public array $backoff = [30, 120];

    public function __construct($token)
    {
        parent::__construct($token);
        $this->onQueue('notifications');
    }

    public function shouldSend($notifiable, string $channel): bool
    {
        return $notifiable->is_active && Password::tokenExists($notifiable, $this->token);
    }

    public function toMail($notifiable): MailMessage
    {
        PrivateMailTransport::assertReady();
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
