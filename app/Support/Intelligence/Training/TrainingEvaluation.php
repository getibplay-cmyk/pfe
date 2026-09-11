<?php

namespace App\Support\Intelligence\Training;

use App\Support\Intelligence\VehicleColor\VehicleColorContract;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class TrainingEvaluation
{
    public function evaluate(array $manifest, string $manifestHash, array $report): array
    {
        Validator::make(['report' => $report], [
            'report' => ['required', 'array:schema_version,campaign_id,manifest_sha256,baseline_version,candidate_version,artifact_sha256,predictions'],
            'report.schema_version' => ['required', 'in:1.0'],
            'report.campaign_id' => ['required', Rule::in([$manifest['campaign_id']])],
            'report.manifest_sha256' => ['required', Rule::in([$manifestHash])],
            'report.baseline_version' => ['required', Rule::in([$manifest['baseline_version']])],
            'report.candidate_version' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{2,99}$/D', Rule::notIn([$manifest['baseline_version']])],
            'report.artifact_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'report.predictions' => ['required', 'array', 'list', 'max:20000'],
            'report.predictions.*' => ['required', 'array:key,baseline,candidate'],
            'report.predictions.*.key' => ['required', 'string', 'distinct:strict'],
        ])->validate();
        $test = collect($manifest['rows'])->where('split', 'test')->keyBy('key');
        $predictions = collect($report['predictions'])->keyBy('key');
        if ($test->isEmpty() || $test->keys()->sort()->values()->all() !== $predictions->keys()->sort()->values()->all()) {
            TrainingDatasetSchema::fail('Le rapport doit couvrir exactement toutes les observations de test de cette campagne.');
        }
        $family = $manifest['family'];
        $rules = [];
        foreach (['baseline', 'candidate'] as $side) {
            $prefix = 'predictions.*.'.$side;
            if ($family === 'damage') {
                $rules += TrainingDatasetSchema::boxRules($prefix);
            } elseif ($family === 'demand') {
                $rules[$prefix] = ['required', 'array', 'list', 'size:7'];
                $rules[$prefix.'.*'] = ['required', 'numeric', 'min:0', 'max:100000'];
            } else {
                $rules[$prefix] = ['present', $family === 'anomaly' ? 'integer' : 'string', match ($family) {
                    'anomaly' => Rule::in([0, 1]),
                    'color' => Rule::in(VehicleColorContract::CLASSES),
                    'plate' => 'max:30',
                }];
            }
        }
        Validator::make(['predictions' => $report['predictions']], $rules)->validate();
        $segments = [];
        $totals = ['baseline' => 0.0, 'candidate' => 0.0];
        $confusion = ['baseline' => [0, 0, 0], 'candidate' => [0, 0, 0]];
        $targetTotal = 0;
        foreach ($test as $key => $row) {
            $p = $predictions[$key];
            foreach (['baseline', 'candidate'] as $side) {
                if ($family === 'demand') {
                    foreach ($p[$side] as $horizon => $value) {
                        $error = abs($row['value'] - $value);
                        $totals[$side] += $error / 7;
                        $segments['J+'.($horizon + 1)][$side] = ($segments['J+'.($horizon + 1)][$side] ?? 0) + $error / $test->count();
                    }
                } elseif ($family === 'damage') {
                    TrainingDatasetSchema::checkBoxes($p[$side]);
                    $counts = $this->matchBoxes($row['boxes'], $p[$side]);
                    foreach ($counts as $index => $count) {
                        $confusion[$side][$index] += $count;
                    }
                } else {
                    $correct = (string) $p[$side] === (string) $row['label'];
                    $totals[$side] += (int) $correct;
                    $label = $family === 'plate' ? 'Toutes les plaques' : (string) $row['label'];
                    $segments[$label][$side] = ($segments[$label][$side] ?? 0) + (int) $correct;
                    if ($family === 'anomaly') {
                        if ((int) $p[$side] === 1 && (int) $row['label'] === 1) {
                            $confusion[$side][0]++;
                        } elseif ((int) $p[$side] === 1) {
                            $confusion[$side][1]++;
                        } elseif ((int) $row['label'] === 1) {
                            $confusion[$side][2]++;
                        }
                    }
                }
            }
            if (in_array($family, ['color', 'anomaly', 'plate'], true)) {
                $segments[$label]['count'] = ($segments[$label]['count'] ?? 0) + 1;
            }
            $targetTotal += $row['value'] ?? 0;
        }
        foreach ($segments as &$segment) {
            if (isset($segment['count'])) {
                foreach (['baseline', 'candidate'] as $side) {
                    $segment[$side] /= $segment['count'];
                }
            }
        }
        unset($segment);
        $scores = [];
        foreach (['baseline', 'candidate'] as $side) {
            [$tp, $fp, $fn] = $confusion[$side];
            $scores[$side] = in_array($family, ['anomaly', 'damage'], true)
                ? 2 * $tp / max(1, 2 * $tp + $fp + $fn)
                : $totals[$side] / $test->count();
        }
        $reasons = [];
        if ($test->count() < 30) {
            $reasons[] = 'Au moins 30 observations de test sont requises pour la revue favorable.';
        }
        $improved = $family === 'demand'
            ? $scores['baseline'] > 0 && $scores['candidate'] <= $scores['baseline'] * .95
            : $scores['candidate'] >= $scores['baseline'] + .01;
        if (! $improved) {
            $reasons[] = $family === 'demand' ? 'Réduction de l’erreur inférieure à 5 %.' : 'Gain inférieur à un point de pourcentage.';
        }
        foreach ($segments as $segment) {
            if (isset($segment['count']) && $segment['count'] < 5) {
                $reasons[] = 'Une classe comporte moins de cinq observations de test.';
                break;
            }
            if ($family === 'demand' ? $segment['candidate'] > $segment['baseline'] * 1.02 + 1e-9 : $segment['candidate'] < $segment['baseline'] - .02) {
                $reasons[] = 'Régression excessive sur un horizon ou une classe.';
                break;
            }
        }
        if ($family === 'color' && count($segments) !== count(VehicleColorContract::CLASSES)) {
            $reasons[] = 'Le test doit couvrir les huit couleurs et la classe de rejet.';
        }
        if ($family === 'anomaly' && count($segments) !== 2) {
            $reasons[] = 'Le test doit contenir des anomalies et des usages normaux confirmés.';
        }
        if ($family === 'damage') {
            $positives = $test->filter(fn ($r) => count($r['boxes']) > 0)->count();
            if ($positives < 10 || $test->count() - $positives < 10) {
                $reasons[] = 'Le test doit contenir dix images avec dommage et dix sans dommage.';
            }
            foreach (['precision', 'recall'] as $metric) {
                foreach (['baseline', 'candidate'] as $side) {
                    [$tp, $fp, $fn] = $confusion[$side];
                    $segments[$metric][$side] = $tp / max(1, $tp + ($metric === 'precision' ? $fp : $fn));
                }
                if ($segments[$metric]['candidate'] < $segments[$metric]['baseline'] - .02) {
                    $reasons[] = 'Baisse excessive de précision ou de rappel des dommages.';
                }
            }
        }

        return ['eligible' => $reasons === [], 'metrics' => [
            ...$scores, 'test_count' => $test->count(), 'metric_label' => TrainingCatalog::get($family)['metric'],
            'segments' => $segments, 'reasons' => array_values(array_unique($reasons)),
            'wape' => $family === 'demand' && $targetTotal > 0 ? ['baseline' => $totals['baseline'] / $targetTotal, 'candidate' => $totals['candidate'] / $targetTotal] : null,
            'provenance' => 'server_recomputed_from_declared_predictions',
        ]];
    }

    private function matchBoxes(array $truth, array $predicted): array
    {
        // Maximum bipartite matching avoids depending on the order supplied by a notebook.
        $edges = [];
        foreach ($predicted as $p => $a) {
            foreach ($truth as $t => $b) {
                $intersection = max(0, min($a['x'] + $a['w'], $b['x'] + $b['w']) - max($a['x'], $b['x']))
                    * max(0, min($a['y'] + $a['h'], $b['y'] + $b['h']) - max($a['y'], $b['y']));
                if ($intersection / max(1e-12, $a['w'] * $a['h'] + $b['w'] * $b['h'] - $intersection) >= .5) {
                    $edges[$p][] = $t;
                }
            }
        }
        $matches = [];
        $visit = function (int $p, array &$seen) use (&$visit, &$matches, $edges): bool {
            foreach ($edges[$p] ?? [] as $t) {
                if (isset($seen[$t])) {
                    continue;
                }
                $seen[$t] = true;
                if (! isset($matches[$t]) || $visit($matches[$t], $seen)) {
                    $matches[$t] = $p;

                    return true;
                }
            }

            return false;
        };
        foreach (array_keys($predicted) as $p) {
            $seen = [];
            $visit($p, $seen);
        }
        $tp = count($matches);

        return [$tp, count($predicted) - $tp, count($truth) - $tp];
    }
}
