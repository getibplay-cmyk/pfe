<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_responses_include_defensive_headers_and_a_correlation_id(): void
    {
        $response = $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');

        $this->assertTrue(Str::isUuid((string) $response->headers->get('X-Correlation-ID')));
        $this->assertFalse($response->headers->has('Content-Security-Policy'));
        $this->assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    public function test_valid_correlation_id_is_preserved(): void
    {
        $correlationId = (string) Str::uuid();

        $this->withHeader('X-Correlation-ID', $correlationId)
            ->get('/health')
            ->assertHeader('X-Correlation-ID', $correlationId);
    }

    public function test_hsts_is_only_added_for_https_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => 443])
            ->get('https://localhost/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
