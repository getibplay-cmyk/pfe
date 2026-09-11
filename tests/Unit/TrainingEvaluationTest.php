<?php

namespace Tests\Unit;

use App\Support\Intelligence\Training\TrainingEvaluation;
use App\Support\Intelligence\VehicleColor\VehicleColorContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TrainingEvaluationTest extends TestCase
{
    public function test_colour_gain_requires_all_nine_classes_and_keeps_per_class_metrics(): void
    {
        $rows = [];
        foreach (VehicleColorContract::CLASSES as $label) {
            foreach (range(1, 5) as $i) {
                $rows[] = ['key' => $label.'_'.$i, 'split' => 'test', 'label' => $label];
            }
        }
        [$manifest, $report] = $this->pair('color', $rows, fn ($r) => ['baseline' => 'red', 'candidate' => $r['label']]);
        $result = app(TrainingEvaluation::class)->evaluate($manifest, str_repeat('a', 64), $report);
        $this->assertTrue($result['eligible']);
        $this->assertEquals(1, $result['metrics']['candidate']);
        $this->assertCount(9, $result['metrics']['segments']);
    }

    public function test_damage_matching_is_one_to_one_and_independent_of_box_order(): void
    {
        $box = ['x' => .1, 'y' => .2, 'w' => .3, 'h' => .3];
        $rows = array_map(fn ($i) => ['key' => (string) $i, 'split' => 'test', 'boxes' => $i < 20 ? [$box] : []], range(0, 39));
        [$manifest, $report] = $this->pair('damage', $rows, fn ($r) => ['baseline' => [], 'candidate' => $r['boxes'] ? [$box, $box] : []]);
        $result = app(TrainingEvaluation::class)->evaluate($manifest, str_repeat('a', 64), $report);
        $this->assertEqualsWithDelta(2 / 3, $result['metrics']['candidate'], .000001);
        $this->assertEqualsWithDelta(.5, $result['metrics']['segments']['precision']['candidate'], .000001);
    }

    public function test_plate_comparison_is_whole_transcript_exact_match(): void
    {
        $rows = array_map(fn ($i) => ['key' => 'r'.$i, 'split' => 'test', 'label' => '12345|أ|6'], range(0, 39));
        [$manifest, $report] = $this->pair('plate', $rows, fn ($r) => ['baseline' => '12345|أ|7', 'candidate' => $r['label']]);
        $result = app(TrainingEvaluation::class)->evaluate($manifest, str_repeat('a', 64), $report);
        $this->assertEquals(0, $result['metrics']['baseline']);
        $this->assertEquals(1, $result['metrics']['candidate']);
    }

    public function test_forged_metrics_and_pass_flags_are_refused(): void
    {
        [$manifest, $report] = $this->pair('anomaly', [['key' => 'row', 'split' => 'test', 'label' => 1]], fn ($r) => ['baseline' => 0, 'candidate' => 1]);
        $report['eligible'] = true;
        $this->expectException(ValidationException::class);
        app(TrainingEvaluation::class)->evaluate($manifest, str_repeat('a', 64), $report);
    }

    public function test_anomaly_f1_is_not_inflated_by_a_majority_of_normal_examples(): void
    {
        $rows = array_map(fn ($i) => ['key' => 'r'.$i, 'split' => 'test', 'label' => $i < 5 ? 1 : 0], range(0, 39));
        [$manifest, $report] = $this->pair('anomaly', $rows, fn ($r) => ['baseline' => 0, 'candidate' => 0]);
        $result = app(TrainingEvaluation::class)->evaluate($manifest, str_repeat('a', 64), $report);
        $this->assertEquals(0, $result['metrics']['candidate']);
        $this->assertFalse($result['eligible']);
    }

    private function pair(string $family, array $rows, callable $prediction): array
    {
        $manifest = ['campaign_id' => 'campaign', 'family' => $family, 'baseline_version' => 'reference', 'rows' => $rows];
        $report = ['schema_version' => '1.0', 'campaign_id' => 'campaign', 'manifest_sha256' => str_repeat('a', 64), 'baseline_version' => 'reference', 'candidate_version' => 'candidate-v2', 'artifact_sha256' => str_repeat('b', 64), 'predictions' => array_map(fn ($r) => ['key' => $r['key'], ...$prediction($r)], $rows)];

        return [$manifest, $report];
    }
}
