<?php

namespace Tests\Unit;

use App\Models\RentalContract;
use App\Models\VehicleInspection;
use App\Support\Export\SpreadsheetSafeCsv;
use App\Support\Intelligence\BuildRentalAnomalyInput;
use App\Support\Intelligence\IntelligencePseudonymizer;
use App\Support\Intelligence\PredictionInput;
use App\Support\Intelligence\PredictionResult;
use App\Support\Intelligence\PredictionScoringService;
use App\Support\Intelligence\RuleBasedScoringService;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class Lot07B1IntelligenceContractTest extends TestCase
{
    public function test_prediction_contracts_are_readonly_versioned_and_ordered(): void
    {
        $input = $this->input();
        $result = app(PredictionScoringService::class)->score($input);

        $this->assertTrue((new ReflectionClass(PredictionInput::class))->isReadOnly());
        $this->assertTrue((new ReflectionClass(PredictionResult::class))->isReadOnly());
        $this->assertSame(PredictionInput::headers(), array_keys($input->toExportRow()));
        $this->assertSame('1.1', $input->toExportRow()['schema_version']);
        $this->assertSame('rentfleet-real-returns-v1.1.0', $input->toExportRow()['dataset_version']);
        $this->assertInstanceOf(RuleBasedScoringService::class, app(PredictionScoringService::class));
        $this->assertSame('rule', $result->source);
    }

    public function test_rule_baseline_is_deterministic_bounded_and_human_review_only(): void
    {
        $service = app(RuleBasedScoringService::class);
        $first = $service->score($this->input());
        $second = $service->score($this->input());
        $serialized = mb_strtolower(json_encode($first->toArray(), JSON_THROW_ON_ERROR));

        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertGreaterThanOrEqual(0, (float) $first->score);
        $this->assertLessThanOrEqual(1, (float) $first->score);
        $this->assertSame('revue recommandée', $first->label);
        $this->assertCount(3, $first->factors);
        $this->assertStringContainsString('revue humaine', $serialized);
        $this->assertStringNotContainsString('fraude certaine', $serialized);
        $this->assertStringNotContainsString('client responsable', $serialized);

        config([
            'intelligence.rule_baseline.thresholds.late_hours' => '100.000000',
            'intelligence.rule_baseline.thresholds.km_per_day' => '1000.000000',
            'intelligence.rule_baseline.thresholds.fuel_drop_pct' => '100.000000',
        ]);
        $this->assertSame('niveau habituel', $service->score($this->input())->label);
    }

    public function test_hmac_pseudonyms_are_stable_domain_separated_and_tenant_scoped(): void
    {
        config(['intelligence.export_hmac_key' => str_repeat('k', 64)]);
        $pseudonymizer = app(IntelligencePseudonymizer::class);
        $event = '2026-08-11T16:00:00Z';

        $this->assertSame($pseudonymizer->tenantKey(10), $pseudonymizer->tenantKey(10));
        $this->assertNotSame($pseudonymizer->tenantKey(10), $pseudonymizer->tenantKey(11));
        $this->assertNotSame($pseudonymizer->agencyKey(10, 20), $pseudonymizer->agencyKey(10, 21));
        $this->assertNotSame($pseudonymizer->contractKey(10, 20), $pseudonymizer->rowId(10, 20, $event));
        $this->assertMatchesRegularExpression('/^t_[a-f0-9]{64}$/', $pseudonymizer->tenantKey(10));
        $this->assertMatchesRegularExpression('/^a_[a-f0-9]{64}$/', $pseudonymizer->agencyKey(10, 20));
        $this->assertMatchesRegularExpression('/^c_[a-f0-9]{64}$/', $pseudonymizer->contractKey(10, 20));
        $this->assertMatchesRegularExpression('/^r_[a-f0-9]{64}$/', $pseudonymizer->rowId(10, 20, $event));
    }

    public function test_complete_closed_contract_is_an_eligible_versioned_input(): void
    {
        config(['intelligence.export_hmac_key' => str_repeat('c', 64)]);
        $contract = new RentalContract;
        $contract->forceFill([
            'id' => 30,
            'tenant_id' => 10,
            'agency_id' => 20,
            'status' => 'closed',
            'actual_start_at' => '2026-08-10 10:00:00+00',
            'expected_return_at' => '2026-08-11 10:00:00+00',
            'actual_return_at' => '2026-08-11 16:00:00+00',
            'start_mileage' => 1000,
            'return_mileage' => 1750,
            'start_fuel_level' => '80.00',
            'return_fuel_level' => '55.00',
        ]);
        $inspection = new VehicleInspection;
        $inspection->forceFill(['inspection_type' => 'return', 'status' => 'completed']);
        $contract->setRelation('inspections', collect([$inspection]));

        $input = app(BuildRentalAnomalyInput::class)->handle($contract);

        $this->assertNotNull($input);
        $this->assertSame(PredictionInput::SCHEMA_VERSION, $input->schemaVersion);
        $this->assertSame('6.000000', $input->lateHours);
        $this->assertSame('600.000000', $input->kmPerDay);
        $this->assertSame('25.000000', $input->fuelDropPct);
    }

    public function test_missing_hmac_key_fails_closed_and_csv_cells_are_formula_safe(): void
    {
        config(['intelligence.export_hmac_key' => '']);
        $pseudonymizer = app(IntelligencePseudonymizer::class);

        $this->assertFalse($pseudonymizer->configured());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('configuration Intelligence');
        $pseudonymizer->tenantKey(1);
    }

    public function test_every_prediction_input_cell_passes_through_spreadsheet_protection(): void
    {
        $row = $this->input(rowId: '=FORMULE_INTERDITE')->toExportRow();
        $safe = array_map(SpreadsheetSafeCsv::cell(...), array_values($row));

        $this->assertSame("'=FORMULE_INTERDITE", $safe[2]);
        $this->assertSame(PredictionInput::headers(), array_keys($row));
    }

    private function input(string $rowId = 'r_'.'1'): PredictionInput
    {
        return new PredictionInput(
            schemaVersion: PredictionInput::SCHEMA_VERSION,
            datasetVersion: PredictionInput::DATASET_VERSION,
            rowId: $rowId,
            tenantKey: 't_'.str_repeat('1', 64),
            agencyKey: 'a_'.str_repeat('2', 64),
            contractKey: 'c_'.str_repeat('3', 64),
            eventAt: '2026-08-11T16:00:00Z',
            lateHours: '6.000000',
            kmPerDay: '600.000000',
            fuelDropPct: '25.000000',
        );
    }
}
