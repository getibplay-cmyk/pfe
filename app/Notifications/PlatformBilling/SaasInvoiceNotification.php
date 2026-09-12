<?php

namespace App\Notifications\PlatformBilling;

use App\Models\PlatformBilling\SaasInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SaasInvoiceNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $invoiceId, public readonly string $eventType)
    {
        // The dedicated database queue uses the SAME default DB connection: transactional outbox.
        $this->onConnection('saas_billing')->onQueue('saas-billing')->beforeCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $notifiable->is_active && ! $notifiable->is_platform_admin
            && $notifiable->hasVerifiedEmail() && $notifiable->isTenantOwner()
            && ($notifiable->role?->is_active ?? false)
            && SaasInvoice::query()->whereKey($this->invoiceId)->where('tenant_id', $notifiable->tenant_id)->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invoice = SaasInvoice::query()->whereKey($this->invoiceId)->where('tenant_id', $notifiable->tenant_id)->firstOrFail();

        return (new MailMessage)
            ->subject(__('BELKHIR SPACE — suivi de facturation'))
            ->line(__('Un événement de facturation a été enregistré : ').match ($this->eventType) {
                'issued' => __('facture émise'), 'paid' => __('règlement enregistré'),
                'overdue' => __('échéance dépassée'), 'reversed' => __('règlement contrepassé'),
                default => __('facture annulée'),
            }.'.')
            ->line(__('Référence : ').$invoice->number)
            ->line(__('Consultez le compte pour connaître son état actuel. Aucun prélèvement automatique n’est effectué.'))
            ->action(__('Consulter la facture'), route('tenant-saas-invoices.show', $invoice));
    }
}
