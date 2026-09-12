<?php

namespace App\Notifications;

use App\Models\Platform\TenantOnboardingInvitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class TenantOnboardingInvitationNotification extends Notification
{
    public function __construct(
        private readonly TenantOnboardingInvitation $invitation,
        private readonly string $token,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            'onboarding-invitations.show',
            $this->invitation->expires_at,
            ['invitation' => $this->invitation->getKey(), 'token' => $this->token],
        );

        return (new MailMessage)
            ->subject(__('Votre invitation — ').config('brand.name'))
            ->greeting(__('Bonjour,'))
            ->line(__('Vous êtes invité à créer l’espace sécurisé de votre entreprise sur ').config('brand.name').'.')
            ->line('L’offre '.$this->invitation->plan->name.__(' inclut une période d’essai de ').$this->invitation->trial_days.__(' jours.'))
            ->action(__('Créer mon espace'), $url)
            ->line(__('Ce lien personnel expire le ').$this->invitation->expires_at->timezone(config('app.timezone'))->format('d/m/Y H:i').'.')
            ->line(__('Si vous n’attendiez pas cette invitation, ignorez ce message.'));
    }
}
