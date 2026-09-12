<?php

namespace Tests\Unit;

use App\Support\Auth\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    #[DataProvider('rfc6238Vectors')]
    public function test_matches_published_sha1_vectors(int $timestamp, string $expected): void
    {
        $this->assertSame($expected, (new Totp)->code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', intdiv($timestamp, 30), 8));
    }

    public static function rfc6238Vectors(): array
    {
        return [[59, '94287082'], [1111111109, '07081804'], [1111111111, '14050471'], [1234567890, '89005924'], [2000000000, '69279037'], [20000000000, '65353130']];
    }

    public function test_generated_secret_has_160_bits_and_is_unique(): void
    {
        $totp = new Totp;
        $secret = $totp->secret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertNotSame($secret, $totp->secret());
    }
}
