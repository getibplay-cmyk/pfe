<?php

namespace Tests\Unit;

use App\Enums\J11AdvisoryModule;
use App\Support\Intelligence\J11\J11CanonicalPayload;
use App\Support\Intelligence\J11\J11ContractDemoGate;
use App\Support\Intelligence\J11\J11SyntheticFixtureRepository;
use App\Support\Intelligence\J11\J11SyntheticFixtureValidator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

class J12ContractAdapterTest extends TestCase
{
    public function test_gate_is_closed_by_default_and_requires_every_safety_invariant(): void
    {
        $gate = app(J11ContractDemoGate::class);

        $this->assertFalse($gate->enabled());
        $this->assertFalse($gate->status()['ready_for_saas']);
        $this->assertFalse($gate->status()['operational_actions_allowed']);

        config(['intelligence.contract_demo.enabled' => true]);
        $this->assertTrue($gate->enabled());

        config(['intelligence.contract_demo.ready_for_saas' => true]);
        $this->assertFalse($gate->enabled());

        config([
            'intelligence.contract_demo.ready_for_saas' => false,
            'intelligence.contract_demo.operational_actions_allowed' => true,
        ]);
        $this->assertFalse($gate->enabled());

        config([
            'intelligence.contract_demo.operational_actions_allowed' => false,
            'intelligence.contract_demo.synthetic_only' => false,
        ]);
        $this->assertFalse($gate->enabled());
    }

    public function test_four_sealed_j11_fixtures_and_schemas_have_the_expected_hashes_and_digests(): void
    {
        $repository = app(J11SyntheticFixtureRepository::class);

        $this->assertCount(4, J11AdvisoryModule::cases());

        foreach (J11AdvisoryModule::cases() as $module) {
            $fixturePath = resource_path('intelligence/j11/fixtures/'.$module->fixtureFile());
            $schemaPath = resource_path('intelligence/j11/schemas/'.$module->schemaFile());

            $this->assertFileExists($fixturePath);
            $this->assertFileExists($schemaPath);
            $this->assertSame($module->fixtureSha256(), hash_file('sha256', $fixturePath));
            $this->assertSame($module->schemaSha256(), hash_file('sha256', $schemaPath));

            $fixture = $repository->get($module);
            $this->assertSame($module, $fixture->module);
            $this->assertSame($module->contractId(), $fixture->payload['contract_id']);
            $this->assertSame($module->value, $fixture->payload['module_id']);
            $this->assertSame($fixture->recordId, $fixture->payload['record_id']);
            $this->assertSame($fixture->fingerprint, $fixture->payload['idempotency']['canonical_payload_sha256']);
            $this->assertSame($fixture->fingerprint, $fixture->payload['audit_event']['canonical_payload_sha256']);
            $this->assertFalse($fixture->payload['scope']['feature_flag']['enabled']);
            $this->assertFalse($fixture->payload['research_status']['ready_for_saas']);
            $this->assertSame('NO_OPERATIONAL_ACTION', $fixture->payload['human_decision']['effect']);
        }
    }

    public function test_validator_rejects_any_activation_claim_or_contract_tampering(): void
    {
        $validator = app(J11SyntheticFixtureValidator::class);
        $repository = app(J11SyntheticFixtureRepository::class);

        $cases = [
            [J11AdvisoryModule::DemandForecast, function (array &$payload): void {
                $payload['scope']['feature_flag']['enabled'] = true;
            }, 'COMMON_FEATURE_FLAG_DISABLED'],
            [J11AdvisoryModule::DemandForecast, function (array &$payload): void {
                $payload['research_status']['ready_for_saas'] = true;
            }, 'COMMON_NOT_READY_FOR_SAAS'],
            [J11AdvisoryModule::DemandForecast, function (array &$payload): void {
                $payload['scope']['automatic_action_allowed'] = true;
            }, 'COMMON_NO_AUTOMATIC_ACTION'],
            [J11AdvisoryModule::DemandForecast, function (array &$payload): void {
                $payload['unexpected'] = true;
            }, 'COMMON_TOP_LEVEL_CLOSED'],
            [J11AdvisoryModule::DemandForecast, function (array &$payload): void {
                $payload['advisory']['email'] = 'interdit@example.invalid';
            }, 'COMMON_NO_FORBIDDEN_KEYS'],
            [J11AdvisoryModule::DemandForecast, function (array &$payload): void {
                $payload['idempotency']['canonical_payload_sha256'] = str_repeat('0', 64);
            }, 'COMMON_IDEMPOTENCY_DIGEST'],
            [J11AdvisoryModule::FleetOptimization, function (array &$payload): void {
                $payload['advisory']['solver_executed'] = true;
            }, 'FLEET_SOLVER_NOT_EXECUTED'],
            [J11AdvisoryModule::PredictiveMaintenance, function (array &$payload): void {
                $payload['advisory']['failure_probability_claimed'] = true;
            }, 'MAINTENANCE_NOT_FAILURE_PROBABILITY'],
            [J11AdvisoryModule::RentalUsageAnomaly, function (array &$payload): void {
                $payload['advisory']['fraud_claimed'] = true;
            }, 'ANOMALY_NO_FRAUD_CLAIM'],
        ];

        foreach ($cases as [$module, $mutate, $expectedFailure]) {
            $payload = $repository->get($module)->payload;
            $mutate($payload);
            $validation = $validator->validate($module, $payload);

            $this->assertFalse($validation->passed(), $expectedFailure);
            $this->assertContains($expectedFailure, $validation->failedChecks());
        }
    }

    public function test_canonical_digest_excludes_audit_envelope_but_detects_business_payload_changes(): void
    {
        $payload = app(J11SyntheticFixtureRepository::class)
            ->get(J11AdvisoryModule::DemandForecast)
            ->payload;
        $canonical = app(J11CanonicalPayload::class);
        $expected = $payload['idempotency']['canonical_payload_sha256'];

        $this->assertSame($expected, $canonical->digest($payload));

        $payload['audit_event']['what']['outcome'] = 'audit-envelope-change';
        $payload['idempotency']['policy'] = 'audit-envelope-change';
        $this->assertSame($expected, $canonical->digest($payload));

        $payload['advisory']['demand_p50_demo'] = 13;
        $this->assertNotSame($expected, $canonical->digest($payload));
    }

    public function test_adapter_contains_no_external_execution_or_scoring_client(): void
    {
        $sources = '';
        foreach ([
            app_path('Actions/Intelligence'),
            app_path('Support/Intelligence/J11'),
        ] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
            foreach ($files as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $sources .= file_get_contents($file->getPathname());
                }
            }
        }

        foreach ([
            'Http::',
            'Process::',
            'PredictionScoringService',
            'RuleBasedScoringService',
            'GuzzleHttp',
            'curl_exec',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sources);
        }
    }
}
