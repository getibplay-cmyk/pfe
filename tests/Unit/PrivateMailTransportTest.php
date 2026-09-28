<?php

namespace Tests\Unit;

use App\Support\Security\PrivateMailTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PrivateMailTransportTest extends TestCase
{
    public static function unsafeTransports(): array
    {
        return [
            'log disguised as smtp' => [['transport' => 'log']],
            'log fallback' => [['transport' => 'failover', 'mailers' => ['smtp', 'log']]],
            'unreviewed provider' => [['transport' => 'custom']],
            'array outside tests' => [['transport' => 'array']],
            'invalid configuration' => ['not-an-array'],
            'missing host' => [['transport' => 'smtp']],
            'url changes transport' => [['transport' => 'smtp', 'url' => 'log://localhost']],
            'query changes transport' => [['transport' => 'smtp', 'url' => 'smtp://smtp.example.test?driver=log']],
            'query changes scheme' => [['transport' => 'smtp', 'url' => 'smtp://smtp.example.test?scheme=log']],
            'unsupported URL driver' => [['transport' => 'smtp', 'url' => 'smtps://smtp.example.test']],
            'no certificate verification' => [['transport' => 'smtp', 'url' => 'smtp://smtp.example.test?verify_peer=0']],
            'no hostname verification' => [['transport' => 'smtp', 'url' => 'smtps://smtp.example.test?verify_peer_name=false']],
            'stream disables verification' => [['transport' => 'smtp', 'host' => 'smtp.example.test', 'stream' => ['ssl' => ['verify_peer' => false]]]],
        ];
    }

    #[DataProvider('unsafeTransports')]
    public function test_recovery_links_cannot_use_an_unsafe_transport(mixed $transport): void
    {
        $this->assertFalse(PrivateMailTransport::allows($this->mail($transport)));
    }

    public function test_direct_smtp_and_smtps_urls_are_accepted_without_contacting_a_server(): void
    {
        foreach ([['transport' => 'smtp', 'host' => 'smtp.example.test'],
            ['transport' => 'smtp', 'url' => 'smtp://smtp.example.test:465?scheme=smtps&verify_peer=true']] as $transport) {
            $this->assertTrue(PrivateMailTransport::allows($this->mail($transport)));
        }
    }

    public function test_only_the_testing_environment_can_capture_notifications_in_memory(): void
    {
        $mail = $this->mail(['transport' => 'array']);
        $this->assertTrue(PrivateMailTransport::allows($mail, testing: true));
        $this->assertFalse(PrivateMailTransport::allows($mail));
        $this->assertFalse(PrivateMailTransport::allows($this->mail(['transport' => 'log']), testing: true));
    }

    public function test_production_refuses_a_url_that_overrides_the_tls_requirement(): void
    {
        $configuration = ['transport' => 'smtp', 'host' => 'smtp.example.test', 'require_tls' => true];
        $this->assertTrue(PrivateMailTransport::allows($this->mail($configuration), requireTls: true));
        $this->assertFalse(PrivateMailTransport::allows($this->mail([
            ...$configuration, 'url' => 'smtp://smtp.example.test?require_tls=false',
        ]), requireTls: true));
        $this->assertFalse(PrivateMailTransport::allows($this->mail([
            ...$configuration, 'verify_peer' => false,
        ]), requireTls: true));
        $this->assertTrue(PrivateMailTransport::allows($this->mail([
            'transport' => 'smtp', 'url' => 'smtp://smtp.example.test:465?scheme=smtps',
        ]), requireTls: true));
    }

    public function test_legacy_driver_cannot_override_a_safe_named_mailer(): void
    {
        $mail = $this->mail(['transport' => 'smtp', 'host' => 'smtp.example.test']);
        $this->assertFalse(PrivateMailTransport::allows([...$mail, 'driver' => 'log']));
    }

    public function test_readiness_uses_the_effective_url_timeout(): void
    {
        $base = ['transport' => 'smtp', 'host' => 'smtp.example.test', 'timeout' => 10];
        $this->assertTrue(PrivateMailTransport::hasBoundedTimeout($this->mail($base)));
        foreach (['0', '-1', '16', 'null', 'invalid', '[]'] as $timeout) {
            $this->assertFalse(PrivateMailTransport::hasBoundedTimeout($this->mail([
                ...$base, 'url' => 'smtp://smtp.example.test?timeout='.$timeout,
            ])));
        }
        $this->assertTrue(PrivateMailTransport::hasBoundedTimeout($this->mail([
            ...$base, 'url' => 'smtp://smtp.example.test?timeout=5',
        ])));
    }

    private function mail(mixed $transport): array
    {
        return ['default' => 'smtp', 'mailers' => ['smtp' => $transport], 'from' => ['address' => 'no-reply@example.test']];
    }
}
