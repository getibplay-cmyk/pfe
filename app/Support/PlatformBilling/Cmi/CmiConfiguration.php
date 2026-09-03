<?php

namespace App\Support\PlatformBilling\Cmi;

use Illuminate\Validation\ValidationException;

class CmiConfiguration
{
    /** @return array{ready: bool, message: string} */
    public function readiness(): array
    {
        if (! config('platform_billing.cmi.enabled')) {
            return ['ready' => false, 'message' => 'Le paiement CMI est désactivé.'];
        }

        $problem = $this->configurationProblem();

        return $problem === null
            ? ['ready' => true, 'message' => 'La passerelle CMI est configurée.']
            : ['ready' => false, 'message' => $problem];
    }

    public function assertReady(): void
    {
        $readiness = $this->readiness();
        if (! $readiness['ready']) {
            throw ValidationException::withMessages(['payment' => $readiness['message']]);
        }
    }

    public function assertVerifiable(): void
    {
        if ($problem = $this->configurationProblem()) {
            throw ValidationException::withMessages(['payment' => $problem]);
        }
    }

    public function endpoint(): string
    {
        return (string) config('platform_billing.cmi.endpoint');
    }

    public function merchantId(): string
    {
        return (string) config('platform_billing.cmi.merchant_id');
    }

    public function storeKey(): string
    {
        return (string) config('platform_billing.cmi.store_key');
    }

    public function successAcknowledgement(): string
    {
        return (string) config('platform_billing.cmi.success_acknowledgement');
    }

    public function failureAcknowledgement(): string
    {
        return (string) config('platform_billing.cmi.failure_acknowledgement');
    }

    private function configurationProblem(): ?string
    {
        if (trim($this->merchantId()) === '' || trim($this->storeKey()) === '') {
            return 'Les identifiants marchands CMI ne sont pas configurés.';
        }

        if (trim((string) config('platform_billing.cmi.merchant_kit_version')) === '') {
            return 'La version du kit marchand CMI n’a pas été confirmée.';
        }

        $endpoint = $this->endpoint();
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        if (parse_url($endpoint, PHP_URL_SCHEME) !== 'https'
            || ! in_array($host, config('platform_billing.cmi.allowed_hosts', []), true)) {
            return 'L’URL de paiement CMI doit utiliser HTTPS et un domaine CMI autorisé.';
        }

        if (! in_array(config('platform_billing.cmi.mode'), ['sandbox', 'live'], true)) {
            return 'Le mode CMI doit être « sandbox » ou « live ».';
        }

        return null;
    }
}
