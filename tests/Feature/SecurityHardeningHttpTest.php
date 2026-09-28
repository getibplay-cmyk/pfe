<?php

namespace Tests\Feature;

use App\Support\Security\SecurityReadiness;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityHardeningHttpTest extends TestCase
{
    public function test_malformed_deep_and_oversized_json_fail_before_a_controller_executes(): void
    {
        Route::post('/__test/bounded-json', fn () => response()->json(['processed' => true]));
        foreach (['{broken', str_repeat('{"a":', 34).'0'.str_repeat('}', 34), '[1,2]', '[]'] as $body) {
            $this->call('POST', '/__test/bounded-json', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $body)
                ->assertStatus(422)->assertHeader('X-Content-Type-Options', 'nosniff');
        }
        $this->call('POST', '/__test/bounded-json', server: ['CONTENT_TYPE' => 'application/json'], content: '{"a":"'.str_repeat('a', 1_048_576).'"}')->assertStatus(413);
        $this->postJson('/__test/bounded-json', ['a' => 'valid'])->assertOk()->assertJson(['processed' => true]);
        $this->postJson('/__test/bounded-json')->assertOk()->assertJson(['processed' => true]);
    }

    public function test_csp_nonces_differ_between_responses_and_script_policy_is_explicitly_report_only(): void
    {
        $first = $this->get('/login')->assertOk();
        $second = $this->get('/login')->assertOk();
        $this->assertNotSame($first->headers->get('Content-Security-Policy-Report-Only'), $second->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertStringNotContainsString('script-src', $first->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('unsafe-eval', $first->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_security_audit_rejects_insecure_production_settings_without_disclosing_values(): void
    {
        config(['app.debug' => true, 'app.key' => 'synthetic-not-for-output', 'security.mfa_require_admins' => false, 'session.secure' => false]);
        $report = app(SecurityReadiness::class)->inspect(production: true);
        $this->assertFalse($report['passed']);
        $statuses = array_column($report['checks'], 'status', 'id');
        $this->assertSame('FAIL', $statuses['auth.admin_mfa']);
        $this->assertSame('FAIL', $statuses['session.secure']);
        $this->assertStringNotContainsString('synthetic-not-for-output', json_encode($report));
        $this->artisan('rentfleet:security-audit', ['--production' => true, '--json' => true])->assertFailed();
    }
}
