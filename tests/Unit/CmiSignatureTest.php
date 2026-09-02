<?php

namespace Tests\Unit;

use App\Support\PlatformBilling\Cmi\CmiSignature;
use PHPUnit\Framework\TestCase;

class CmiSignatureTest extends TestCase
{
    public function test_ver3_signature_sorts_keys_escapes_reserved_characters_and_excludes_hash_fields(): void
    {
        $parameters = [
            'amount' => '799.90',
            'callbackUrl' => 'https://app.example.test/billing/cmi/callback',
            'clientid' => 'merchant-123',
            'currency' => '504',
            'failUrl' => 'https://app.example.test/fail',
            'hashAlgorithm' => 'ver3',
            'lang' => 'fr',
            'oid' => 'BS-01TEST',
            'okUrl' => 'https://app.example.test/ok',
            'rnd' => 'fixed-random',
            'shopUrl' => 'https://app.example.test/abonnement',
            'storetype' => '3D_PAY_HOSTING',
            'TranType' => 'PreAuth',
        ];
        $signature = new CmiSignature;

        $hash = $signature->sign($parameters, 'store|key\\2026');

        $this->assertSame(
            'GFKSK1b3SNIgdJRPNu082AjVVRyw0SSYZXdEnBWfbBhdkoksyJoGZ54oglbgF4YxeTG03yJ3tZeB2yRstGP6Lw==',
            $hash,
        );
        $this->assertTrue($signature->verify([...$parameters, 'HASH' => $hash, 'encoding' => 'UTF-8'], 'store|key\\2026'));
        $this->assertFalse($signature->verify([...$parameters, 'HASH' => $hash], 'wrong-key'));
    }
}
