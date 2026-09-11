<?php

namespace App\Jobs;

use App\Models\CustomerPortalAccess;
use App\Notifications\CustomerPortalLinkNotification;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\CustomerPortalContext;
use App\Support\Tenancy\CustomerPortalMail;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SendCustomerPortalLink implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 30;

    public function __construct(public readonly string $accessId, private readonly string $emailFingerprint)
    {
        $this->afterCommit();
    }

    public function handle(CustomerPortalContext $portal, CustomerPortalMail $mail): void
    {
        $access = CustomerPortalAccess::withoutGlobalScopes()->find($this->accessId);
        if (! $access || $access->consumed_at !== null) {
            return;
        }
        try {
            $customer = $portal->customer($access);
        } catch (HttpExceptionInterface|ModelNotFoundException) {
            return;
        }
        if (! filter_var($customer->email, FILTER_VALIDATE_EMAIL)
            || ! hash_equals($this->emailFingerprint, hash('sha256', mb_strtolower(trim($customer->email))))) {
            return;
        }
        if (! $mail->ready()) {
            throw new \RuntimeException('L’envoi du lien locataire nécessite un transport SMTP configuré.');
        }
        try {
            $url = URL::temporarySignedRoute('portal.enter', $access->expires_at, ['access' => $access->id]);
            Notification::route('mail', $customer->email)->notify(new CustomerPortalLinkNotification($url));
        } catch (\Throwable) {
            // Transport exceptions can contain credentials or message bodies.
            throw new \RuntimeException('Le lien locataire n’a pas pu être envoyé. Vérifiez le service de messagerie.');
        }
        app(TenantContext::class)->run((int) $access->tenant_id, fn () => app(AuditRecorder::class)->record('customer.portal_link_sent', $customer));
    }
}
