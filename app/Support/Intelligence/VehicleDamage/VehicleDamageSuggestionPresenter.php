<?php

namespace App\Support\Intelligence\VehicleDamage;

use App\Enums\VehicleDamagePredictionStatus;
use App\Models\VehicleDamagePredictionRun;
use UnexpectedValueException;

final class VehicleDamageSuggestionPresenter
{
    /**
     * @return list<array{
     *     type: string,
     *     label: string,
     *     confidence: float,
     *     box: array{x: int, y: int, width: int, height: int}
     * }>
     */
    public function detections(VehicleDamagePredictionRun $run): array
    {
        if ($run->status !== VehicleDamagePredictionStatus::Succeeded
            || ! in_array($run->quality_status, VehicleDamageContract::QUALITY_STATUSES, true)
            || ! is_array($run->candidate_regions)) {
            throw new UnexpectedValueException('Invalid damage suggestion state.');
        }

        if ($run->quality_status === 'abstained') {
            if ($run->suggested_damage !== null
                || $run->max_probability_damage !== null
                || $run->candidate_regions !== []) {
                throw new UnexpectedValueException('Invalid abstained damage suggestion.');
            }

            return [];
        }

        $maximum = $this->finiteProbability($run->max_probability_damage);
        if (! is_bool($run->suggested_damage)) {
            throw new UnexpectedValueException('Invalid damage decision.');
        }
        if (! $run->suggested_damage) {
            if ($run->candidate_regions !== []
                || $maximum >= (float) $run->decision_threshold) {
                throw new UnexpectedValueException('Invalid empty damage suggestion.');
            }

            return [];
        }

        if ($run->candidate_regions === []) {
            throw new UnexpectedValueException('Missing damage regions.');
        }

        $detections = [];
        foreach ($run->candidate_regions as $region) {
            if (! is_array($region)
                || ! $this->hasExactKeys(
                    $region,
                    ['x', 'y', 'width', 'height', 'probability'],
                )
                || ! is_int($region['x'])
                || ! is_int($region['y'])
                || ! is_int($region['width'])
                || ! is_int($region['height'])
                || $region['x'] < 0
                || $region['y'] < 0
                || $region['width'] < 1
                || $region['height'] < 1
                || $run->input_width < $region['x'] + $region['width']
                || $run->input_height < $region['y'] + $region['height']) {
                throw new UnexpectedValueException('Invalid damage region.');
            }
            $confidence = $this->finiteProbability($region['probability'] ?? null);
            if ($confidence < (float) $run->decision_threshold) {
                throw new UnexpectedValueException('Damage region below the frozen threshold.');
            }
            $detections[] = [
                'type' => 'possible_damage',
                'label' => 'Zone de dommage possible',
                'confidence' => round($confidence, 6),
                'box' => [
                    'x' => $region['x'],
                    'y' => $region['y'],
                    'width' => $region['width'],
                    'height' => $region['height'],
                ],
            ];
        }

        if (abs($maximum - $detections[0]['confidence']) > 0.000001) {
            throw new UnexpectedValueException('Damage confidence mismatch.');
        }

        return $detections;
    }

    private function finiteProbability(mixed $value): float
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            throw new UnexpectedValueException('Invalid damage confidence.');
        }
        if (! is_numeric($value)) {
            throw new UnexpectedValueException('Invalid damage confidence.');
        }

        $probability = (float) $value;
        if (! is_finite($probability) || $probability < 0 || $probability > 1) {
            throw new UnexpectedValueException('Invalid damage confidence.');
        }

        return $probability;
    }

    /** @param list<string> $expected */
    private function hasExactKeys(array $value, array $expected): bool
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);

        return $keys === $expected;
    }
}
