<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_private_errors_and_early_failures_are_not_cached(): void
    {
        $this->get('/tenant/missing-private-page')->assertNotFound()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        Route::get('/__test/service-unavailable', fn () => abort(503));
        $this->get('/__test/service-unavailable')->assertStatus(503)
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $middleware = app(Kernel::class)->getGlobalMiddleware();
        $this->assertLessThan(array_search(PreventRequestsDuringMaintenance::class, $middleware, true),
            array_search(SecurityHeaders::class, $middleware, true));
    }

    public function test_untrusted_hosts_are_rejected_in_production(): void
    {
        config(['app.url' => 'https://belkhir.example']);
        $this->app->detectEnvironment(fn () => 'production');
        $this->get('https://attacker.invalid/login')->assertStatus(400);
    }

    public function test_sensitive_auth_and_payment_routes_have_separate_rate_limit_buckets(): void
    {
        $prefixes = [];
        foreach (['password.email', 'password.store', 'verification.verify', 'verification.send',
            'billing.cmi.callback', 'billing.cmi.return', 'tenant-saas-checkout.store'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $throttle = collect($route->gatherMiddleware())->first(fn ($value) => str_starts_with($value, 'throttle:'));
            $parts = explode(',', $throttle);
            $this->assertCount(3, $parts, $name);
            $this->assertNotContains($parts[2], $prefixes);
            $prefixes[] = $parts[2];
        }
    }

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
