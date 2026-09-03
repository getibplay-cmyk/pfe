<?php

namespace App\Support\PlatformBilling\Cmi;

use App\Models\PlatformBilling\SaasPaymentAttempt;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class CmiHostedGateway
{
    public function __construct(
        private readonly CmiConfiguration $configuration,
        private readonly CmiSignature $signature,
    ) {}

    /** @return array<string, string> */
    public function checkoutFields(SaasPaymentAttempt $attempt): array
    {
        $this->configuration->assertReady();
        $returnLinkExpiresAt = $attempt->expires_at->addMinutes(10);

        $fields = [
            'amount' => $attempt->amount,
            'callbackUrl' => route('billing.cmi.callback'),
            'clientid' => $this->configuration->merchantId(),
            'currency' => (string) config('platform_billing.cmi.currency_numeric'),
            'failUrl' => URL::temporarySignedRoute(
                'billing.cmi.return',
                $returnLinkExpiresAt,
                ['attempt' => $attempt, 'result' => 'failed'],
            ),
            'hashAlgorithm' => (string) config('platform_billing.cmi.hash_algorithm'),
            'lang' => (string) config('platform_billing.cmi.language'),
            'oid' => $attempt->merchant_order_id,
            'okUrl' => URL::temporarySignedRoute(
                'billing.cmi.return',
                $returnLinkExpiresAt,
                ['attempt' => $attempt, 'result' => 'success'],
            ),
            'rnd' => Str::random(24),
            'shopUrl' => route('subscription.public'),
            'storetype' => (string) config('platform_billing.cmi.store_type'),
            'TranType' => (string) config('platform_billing.cmi.transaction_type'),
        ];
        $fields['HASH'] = $this->signature->sign($fields, $this->configuration->storeKey());

        return $fields;
    }

    /** @param array<string, scalar|null> $parameters */
    public function signatureIsValid(array $parameters): bool
    {
        $this->configuration->assertVerifiable();

        return $this->signature->verify($parameters, $this->configuration->storeKey());
    }

    public function endpoint(): string
    {
        return $this->configuration->endpoint();
    }
}
