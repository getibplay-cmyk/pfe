<?php

namespace Tests\Unit;

use App\Support\Audit\AuditRecorder;
use PHPUnit\Framework\TestCase;

class AuditBoundsTest extends TestCase
{
    public function test_nested_secrets_control_characters_and_large_structures_are_bounded(): void
    {
        $audit = new AuditRecorder;
        $values = ['safe' => "hello\r\nforged-event", 'nested' => ['api_key' => 'synthetic-secret', 'password' => 'synthetic-password', 'name' => 'Synthetic name']];
        $safe = $audit->sanitize($values);
        $this->assertSame('hello  forged-event', $safe['safe']);
        $this->assertSame(['name' => 'Synthetic name'], $safe['nested']);
        $wide = array_fill(0, 100, array_fill(0, 100, str_repeat('x', 3000)));
        $bounded = $audit->sanitize($wide);
        $this->assertLessThan(650000, strlen(json_encode($bounded)));
        $deep = ['safe' => 'leaf'];
        for ($i = 0; $i < 12; $i++) {
            $deep = ['nested' => $deep];
        }
        $this->assertStringNotContainsString('leaf', json_encode($audit->sanitize($deep)));
        $this->assertStringContainsString('truncated', json_encode($audit->sanitize($deep)));
    }
}
