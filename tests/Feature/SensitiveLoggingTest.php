<?php

namespace Tests\Feature;

use App\Support\Audit\AuditRecorder;
use Tests\TestCase;

class SensitiveLoggingTest extends TestCase
{
    public function test_audit_sanitizer_recursively_removes_sensitive_values(): void
    {
        $sanitized = app(AuditRecorder::class)->sanitize([
            'name' => 'Client fictif',
            'password' => 'secret-value',
            'nested' => [
                'identity_number_encrypted' => 'ciphertext',
                'card_number' => '4111111111111111',
                'safe' => 'visible',
            ],
            'insurer_reference' => 'private-reference',
        ]);

        $encoded = json_encode($sanitized);
        $this->assertSame('Client fictif', $sanitized['name']);
        $this->assertSame('visible', $sanitized['nested']['safe']);
        $this->assertStringNotContainsString('secret-value', $encoded);
        $this->assertStringNotContainsString('4111111111111111', $encoded);
        $this->assertStringNotContainsString('ciphertext', $encoded);
        $this->assertStringNotContainsString('private-reference', $encoded);
    }

    public function test_health_failure_logging_uses_no_exception_or_connection_detail(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertStringContainsString("Log::warning('Health check database unavailable.')", $routes);
        $this->assertStringNotContainsString('report($exception)', $routes);
        $this->assertStringNotContainsString('DB_PASSWORD', $routes);
    }
}
