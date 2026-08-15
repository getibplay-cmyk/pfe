<?php

namespace Tests\Unit;

use App\Support\Intelligence\DemandForecasting\DemandForecastCanonicalPayload;
use Tests\TestCase;

class DemandForecastCanonicalPayloadTest extends TestCase
{
    public function test_php_canonical_digest_matches_the_python_adapter_vector(): void
    {
        $payload = [
            'z' => 'km',
            'idempotency' => [
                'key' => '00000000-0000-4000-8000-000000000001',
                'policy' => 'SAME_KEY_SAME_PAYLOAD_ONLY',
                'canonical_payload_sha256' => 'ignored',
            ],
            'a' => [
                'label' => 'prévision',
                'items' => [true, null, 7],
            ],
        ];

        $this->assertSame(
            'c801f46e27f6b26987837b2ba346a329eb15678eef5e405531f20e6154558801',
            app(DemandForecastCanonicalPayload::class)->digest($payload),
        );
    }
}
