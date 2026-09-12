<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerPortalLinkNotification extends Notification
{
    public function __construct(private readonly string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__('Votre espace locataire — ').config('brand.name'))
            ->greeting(__('Bonjour,'))->line(__('Votre agence vous donne accès à vos réservations, contrats, factures et justificatifs.'))
            ->action(__('Ouvrir mon espace locataire'), $this->url)
            ->line(__('Ce lien personnel est utilisable une seule fois pendant 48 heures à partir de sa création. La session dure au maximum 30 minutes, sans dépasser la validité du lien.'))
            ->line(__('Ne transférez pas ce message. Si vous n’attendiez pas cet accès, contactez votre agence.'));
    }
}
