<?php

namespace Tests\Unit;

use App\Support\Intelligence\J14\J14CanonicalPayload;
use JsonException;
use PHPUnit\Framework\TestCase;

class J14ResultBatchContractTest extends TestCase
{
    /** @throws JsonException */
    public function test_canonical_digest_sorts_objects_preserves_lists_and_excludes_only_its_digest_field(): void
    {
        $canonical = new J14CanonicalPayload;
        $first = [
            'z' => ['second' => 2, 'first' => 1],
            'items' => [['b' => true, 'a' => false], 'last'],
            'idempotency' => [
                'policy' => 'SAME_KEY_SAME_PAYLOAD_ONLY',
                'canonical_payload_sha256' => str_repeat('0', 64),
                'key' => '00000000-0000-4000-8000-000000000001',
            ],
        ];
        $sameSemanticPayload = [
            'idempotency' => [
                'key' => '00000000-0000-4000-8000-000000000001',
                'canonical_payload_sha256' => str_repeat('f', 64),
                'policy' => 'SAME_KEY_SAME_PAYLOAD_ONLY',
            ],
            'items' => [['a' => false, 'b' => true], 'last'],
            'z' => ['first' => 1, 'second' => 2],
        ];

        $this->assertSame($canonical->digest($first), $canonical->digest($sameSemanticPayload));
        $this->assertNotSame(
            $canonical->digest($first),
            $canonical->digest([...$first, 'items' => array_reverse($first['items'])]),
        );
        $this->assertStringEndsWith("\n", $canonical->encode($first));
        $this->assertStringNotContainsString('\\/', $canonical->encode(['url' => 'https://example.invalid/a']));
    }

    public function test_machine_readable_schema_is_closed_and_freezes_all_safety_boundaries(): void
    {
        $path = dirname(__DIR__, 2).'/docs/intelligence/schemas/j14-result-batch-v1.0.0.json';
        $schema = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame('1.0.0', $schema['properties']['schema_version']['const']);
        $this->assertSame('not_run_synthetic_contract_fixture', $schema['properties']['source']['properties']['computation_status']['const']);
        $this->assertSame('NO_OPERATIONAL_ACTION', $schema['properties']['human_review']['properties']['effect']['const']);
        $this->assertTrue($schema['properties']['safety']['properties']['synthetic_only']['const']);

        foreach ([
            'contains_real_customer_data',
            'contains_direct_identifiers',
            'contains_coordinates',
            'automatic_action_allowed',
            'ready_for_saas',
            'production_allowed',
        ] as $boundary) {
            $this->assertFalse($schema['properties']['safety']['properties'][$boundary]['const'], $boundary);
        }

        foreach (['source', 'export', 'human_review', 'safety', 'idempotency'] as $object) {
            $this->assertFalse($schema['properties'][$object]['additionalProperties'], $object);
        }
        foreach (['result', 'lateHoursFactor', 'kmPerDayFactor', 'fuelDropFactor'] as $definition) {
            $this->assertFalse($schema['$defs'][$definition]['additionalProperties'], $definition);
        }

        $serialized = json_encode($schema, JSON_THROW_ON_ERROR);
        foreach (['model_score', 'probability', 'customer_id', 'vehicle_id', 'latitude', 'longitude'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }
}
