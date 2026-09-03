<?php

$cmiMode = env('CMI_PAYMENT_MODE', 'sandbox');

return [
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
        'attempt_ttl_minutes' => max(5, (int) env('CMI_ATTEMPT_TTL_MINUTES', 30)),
        'success_acknowledgement' => env('CMI_SUCCESS_ACKNOWLEDGEMENT', 'ACTION=POSTAUTH'),
        'failure_acknowledgement' => env('CMI_FAILURE_ACKNOWLEDGEMENT', 'ACTION=DECLINE'),
    ],
];
