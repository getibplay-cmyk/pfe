<?php

$cmiMode = env('CMI_PAYMENT_MODE', 'sandbox');

return [
    'self_service_enabled' => (bool) env('SAAS_SELF_SERVICE_ENABLED', false),
    'renewals_enabled' => (bool) env('SAAS_RENEWALS_ENABLED', false),
    'grace_days' => min(30, max(0, (int) env('SAAS_BILLING_GRACE_DAYS', 7))),
    'invoice_issuer' => env('SAAS_INVOICE_ISSUER', 'BELKHIR SPACE'),
    'onboarding' => [
        'invitation_ttl_hours' => min(168, max(1, (int) env('SAAS_INVITATION_TTL_HOURS', 72))),
        'default_trial_days' => min(90, max(1, (int) env('SAAS_DEFAULT_TRIAL_DAYS', 14))),
    ],
    'cmi' => [
        'enabled' => (bool) env('CMI_PAYMENT_ENABLED', false),
        'mode' => $cmiMode,
        'endpoint' => env(
            'CMI_PAYMENT_URL',
            $cmiMode === 'live'
                ? 'https://payment.cmi.co.ma/fim/est3Dgate'
                : 'https://testpayment.cmi.co.ma/fim/est3Dgate',
        ),
        'allowed_hosts' => ['testpayment.cmi.co.ma', 'payment.cmi.co.ma'],
        'merchant_id' => env('CMI_MERCHANT_ID'),
        'store_key' => env('CMI_STORE_KEY'),
        'merchant_kit_version' => env('CMI_MERCHANT_KIT_VERSION'),
        'store_type' => env('CMI_STORE_TYPE', '3D_PAY_HOSTING'),
        'transaction_type' => env('CMI_TRANSACTION_TYPE', 'PreAuth'),
        'hash_algorithm' => 'ver3',
        'language' => 'fr',
        'currency' => 'MAD',
        'currency_numeric' => '504',
        'attempt_ttl_minutes' => min(120, max(5, (int) env('CMI_ATTEMPT_TTL_MINUTES', 30))),
        'success_acknowledgement' => env('CMI_SUCCESS_ACKNOWLEDGEMENT', 'ACTION=POSTAUTH'),
        'failure_acknowledgement' => env('CMI_FAILURE_ACKNOWLEDGEMENT', 'ACTION=DECLINE'),
    ],
];
