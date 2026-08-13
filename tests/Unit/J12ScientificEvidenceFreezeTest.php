<?php

namespace Tests\Unit;

use App\Enums\J11AdvisoryModule;
use JsonException;
use Tests\TestCase;

class J12ScientificEvidenceFreezeTest extends TestCase
{
    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function manifest(): array
    {
        $contents = file_get_contents(base_path('docs/intelligence/j12-scientific-evidence-manifest.json'));

        $this->assertNotFalse($contents);

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @throws JsonException
     */
    public function test_manifest_seals_the_four_j11_modules_and_their_artifacts(): void
    {
        $manifest = $this->manifest();
        $modules = [];

        foreach ($manifest['modules'] as $module) {
            $modules[$module['id']] = $module;
        }

        $this->assertSame('1.0.0', $manifest['manifest_version']);
        $this->assertSame('c1140ba6dfa0b5ea6a4d3f3d9ba418773a4ec8ee', $manifest['source_commit']);
        $this->assertSame('J13_CONSULTATIVE_DISABLED_ONLY', $manifest['decision']);
        $this->assertCount(4, $modules);
        $this->assertEqualsCanonicalizing(
            array_map(static fn (J11AdvisoryModule $module): string => $module->value, J11AdvisoryModule::cases()),
            array_keys($modules),
        );

        foreach (J11AdvisoryModule::cases() as $module) {
            $entry = $modules[$module->value];

            $this->assertSame($module->gateDecision(), $entry['gate_decision']);
            $this->assertSame($module->auditScore(), $entry['audit_score']);
            $this->assertFalse($entry['ready_for_saas']);
            $this->assertFalse($entry['production_allowed']);
            $this->assertSame(
                'resources/intelligence/j11/fixtures/'.$module->fixtureFile(),
                $entry['fixture']['path'],
            );
            $this->assertSame(
                'resources/intelligence/j11/schemas/'.$module->schemaFile(),
                $entry['schema']['path'],
            );
            $this->assertSame($module->fixtureSha256(), $entry['fixture']['sha256']);
            $this->assertSame($module->schemaSha256(), $entry['schema']['sha256']);
            $this->assertArrayHasKey($entry['evidence_class'], $manifest['evidence_taxonomy']);
            $this->assertNotSame('', $entry['benchmark_role']);
            $this->assertSame(
                $entry['fixture']['sha256'],
                hash_file('sha256', base_path($entry['fixture']['path'])),
            );
            $this->assertSame(
                $entry['schema']['sha256'],
                hash_file('sha256', base_path($entry['schema']['path'])),
            );
            $this->assertNotSame('', $entry['claim_allowed']);
            $this->assertNotEmpty($entry['claims_forbidden']);
        }
    }

    /**
     * @throws JsonException
     */
    public function test_j9_benchmark_and_legacy_isolation_forest_are_not_conflated(): void
    {
        $lineage = $this->manifest()['anomaly_lineage'];
        $j9 = $lineage['j9_public_proxy_benchmark'];
        $legacy = $lineage['legacy_lot07b1_synthetic_artifact'];
        $fixture = $lineage['j11_j12_fixture'];

        $this->assertSame('robust_mad_top2', $j9['selected_candidate']);
        $this->assertSame('rental_anomaly_iforest', $legacy['name']);
        $this->assertNotSame($j9['selected_candidate'], $legacy['name']);
        $this->assertSame('synthetic', $legacy['training_data']);
        $this->assertSame(
            'separate_legacy_artifact_not_the_j9_selected_candidate',
            $legacy['relationship_to_j9'],
        );
        $this->assertFalse($j9['allowed_in_j13']);
        $this->assertFalse($legacy['allowed_in_j13']);
        $this->assertSame('not_run_synthetic_contract_fixture', $fixture['computation_status']);
        $this->assertSame('no_model_or_solver_was_executed', $fixture['relationship_to_models']);
    }

    /**
     * @throws JsonException
     */
    public function test_j13_entry_gate_remains_consultative_and_closed(): void
    {
        $gate = $this->manifest()['j13_entry_gate'];

        $this->assertSame('consultative_disabled_only', $gate['mode']);
        $this->assertSame(4, $gate['module_count']);

        foreach ([
            'new_model_allowed',
            'dynamic_pricing_allowed',
            'feature_flags_enabled',
            'ready_for_saas',
            'production_allowed',
            'inference_allowed',
            'training_allowed',
            'solver_allowed',
            'historical_public_output_import_allowed',
            'automatic_action_allowed',
            'operational_business_write_allowed',
        ] as $closedBoundary) {
            $this->assertFalse($gate[$closedBoundary], $closedBoundary);
        }

        $this->assertTrue($gate['human_decision_required']);
        $this->assertTrue($gate['tenant_and_agency_server_derived']);
        $this->assertSame('NO_OPERATIONAL_ACTION', $gate['decision_effect']);
    }
}
