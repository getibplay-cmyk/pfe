<?php

namespace App\Support\Intelligence\VehicleDamage;

use App\Exceptions\VehicleDamageExecutionException;
use App\Models\VehicleDamagePredictionRun;
use JsonException;

class VehicleDamageResultValidator
{
    public function validate(string $json, VehicleDamagePredictionRun $run): ValidatedVehicleDamageResult
    {
        try {
            $payload = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new VehicleDamageExecutionException('DAMAGE_OUTPUT_JSON_INVALID');
        }
        if (! is_array($payload) || ! $this->hasExactKeys(
            $payload,
            ['schema_version', 'model', 'input', 'quality', 'scan', 'result', 'safety'],
        )) {
            throw new VehicleDamageExecutionException('DAMAGE_OUTPUT_CONTRACT_INVALID');
        }

        $model = $payload['model'] ?? null;
        $input = $payload['input'] ?? null;
        $safety = $payload['safety'] ?? null;
        if (! is_array($model)
            || ! is_array($input)
            || ! is_array($safety)
            || ! $this->hasExactKeys($model, [
                'name',
                'version',
                'artifact_sha256',
                'model_card_sha256',
                'decision_threshold',
            ])
            || ! $this->hasExactKeys($input, [
                'run_id',
                'sha256',
                'bytes',
                'mime',
                'width',
                'height',
            ])
            || ! $this->hasExactKeys($safety, [
                'mode',
                'human_validation_required',
                'automatic_business_action_allowed',
                'operational_effect',
                'local_pilot_required',
                'domain_validation_status',
                'pixel_precise_localization',
            ])
            || $payload['schema_version'] !== VehicleDamageContract::RESULT_SCHEMA_VERSION
            || $model['name'] !== VehicleDamageContract::MODEL_NAME
            || $model['version'] !== VehicleDamageContract::MODEL_VERSION
            || $model['artifact_sha256'] !== $run->model_artifact_sha256
            || $model['model_card_sha256'] !== $run->model_card_sha256
            || ! $this->numericEquals($model['decision_threshold'], (float) $run->decision_threshold)
            || $input['run_id'] !== $run->run_id
            || $input['sha256'] !== $run->input_sha256
            || $input['bytes'] !== $run->input_bytes
            || $input['mime'] !== $run->input_mime
            || $input['width'] !== $run->input_width
            || $input['height'] !== $run->input_height
            || $safety['mode'] !== VehicleDamageContract::MODE
            || $safety['human_validation_required'] !== true
            || $safety['automatic_business_action_allowed'] !== false
            || $safety['operational_effect'] !== VehicleDamageContract::OPERATIONAL_EFFECT
            || $safety['local_pilot_required'] !== true
            || $safety['domain_validation_status'] !== VehicleDamageContract::DOMAIN_VALIDATION_STATUS
            || $safety['pixel_precise_localization'] !== false) {
            throw new VehicleDamageExecutionException('DAMAGE_OUTPUT_CONTRACT_INVALID');
        }

        [$qualityStatus, $qualityReasons, $qualityMetrics] = $this->quality($payload['quality'] ?? null);
        $evaluatedPatches = $this->scan($payload['scan'] ?? null, $qualityStatus);
        [$maxProbability, $suggestedDamage, $regions] = $this->result(
            $payload['result'] ?? null,
            $run,
            $qualityStatus,
        );

        if (($qualityStatus === 'abstained'
                && ($evaluatedPatches !== 0
                    || $maxProbability !== null
                    || $suggestedDamage !== null
                    || $regions !== []))
            || ($qualityStatus === 'usable'
                && ($evaluatedPatches < 1
                    || $maxProbability === null
                    || $suggestedDamage === null))) {
            throw new VehicleDamageExecutionException('DAMAGE_OUTPUT_POLICY_MISMATCH');
        }

        return new ValidatedVehicleDamageResult(
            qualityStatus: $qualityStatus,
            qualityReasons: $qualityReasons,
            qualityMetrics: $qualityMetrics,
            evaluatedPatches: $evaluatedPatches,
            maxProbabilityDamage: $maxProbability,
            suggestedDamage: $suggestedDamage,
            candidateRegions: $regions,
        );
    }

    /**
     * @return array{string, list<string>, array{brightness: float, contrast: float, sharpness: float}}
     */
    private function quality(mixed $quality): array
    {
        if (! is_array($quality)
            || ! $this->hasExactKeys(
                $quality,
                ['status', 'reasons', 'brightness', 'contrast', 'sharpness'],
            )
            || ! in_array($quality['status'] ?? null, VehicleDamageContract::QUALITY_STATUSES, true)
            || ! is_array($quality['reasons'] ?? null)
            || ! array_is_list($quality['reasons'])) {
            throw new VehicleDamageExecutionException('DAMAGE_OUTPUT_QUALITY_INVALID');
        }
        $reasons = $quality['reasons'];
        if (count($reasons) > count(VehicleDamageContract::QUALITY_REASONS)
            || array_unique($reasons, SORT_STRING) !== $reasons
            || collect($reasons)->contains(
                fn (mixed $reason): bool => ! is_string($reason)
                    || ! in_array($reason, VehicleDamageContract::QUALITY_REASONS, true),
            )) {
            throw new VehicleDamageExecutionException('DAMAGE_OUTPUT_QUALITY_INVALID');
        }
        $metrics = [];
        foreach (['brightness', 'contrast', 'sharpness'] as $name) {
            $value = $quality[$name] ?? null;
            if (! is_int($value) && ! is_float($value)
                || ! is_finite((float) $value)
                || (float) $value < 0
                || (float) $value > 1) {
                throw new VehicleDamageExecutionException('DAMAGE_OUTPUT_QUALITY_INVALID');
            }
            $metrics[$name] = (float) $value;
        }
        if (($quality['status'] === 'usable' && $reasons !== [])
            || ($quality['status'] === 'abstained' && $reasons === [])) {
            throw new VehicleDamageExecutionException('DAMAGE_OUTPUT_QUALITY_INVALID');
        }

        return [$quality['status'], $reasons, $metrics];
    }

    private function scan(mixed $scan, string $qualityStatus): int
    {
        if (! is_array($scan)
            || ! $this->hasExactKeys(
                $scan,
                ['mode', 'evaluated_patches', 'overlap_ratio', 'candidate_limit'],
            )
            || $scan['mode'] !== 'coarse_overlapping_patches'
            || ! is_int($scan['evaluated_patches'] ?? null)
            || $scan['evaluated_patches'] < 0
            || $scan['evaluated_patches'] > min(
                64,
                (int) config('intelligence.vehicle_damage_v1.max_scan_patches'),
            )
            || ! $this->numericEquals($scan['overlap_ratio'] ?? null, VehicleDamageContract::OVERLAP_RATIO)
            || $scan['candidate_limit'] !== VehicleDamageContract::MAX_CANDIDATES
            || ($qualityStatus === 'abstained' && $scan['evaluated_patches'] !== 0)) {
            throw new VehicleDamageExecutionException('DAMAGE_OUTPUT_SCAN_INVALID');
        }

        return $scan['evaluated_patches'];
    }

    /**
     * @return array{?float, ?bool, list<array{x: int, y: int, width: int, height: int, probability: float}>}
     */
    private function result(mixed $result, VehicleDamagePredictionRun $run, string $qualityStatus): array
    {
        if (! is_array($result)
            || ! $this->hasExactKeys($result, [
                'suggested_damage',
                'max_probability_damage',
                'candidate_count',
                'candidate_regions',
            ])
            || ! is_int($result['candidate_count'] ?? null)
            || $result['candidate_count'] < 0
            || $result['candidate_count'] > VehicleDamageContract::MAX_CANDIDATES
            || ! is_array($result['candidate_regions'] ?? null)
            || ! array_is_list($result['candidate_regions'])
            || count($result['candidate_regions']) !== $result['candidate_count']) {
            throw new VehicleDamageExecutionException('DAMAGE_OUTPUT_RESULT_INVALID');
        }
        if ($qualityStatus === 'abstained') {
            if ($result['suggested_damage'] !== null
                || $result['max_probability_damage'] !== null
                || $result['candidate_count'] !== 0) {
                throw new VehicleDamageExecutionException('DAMAGE_OUTPUT_POLICY_MISMATCH');
            }

            return [null, null, []];
        }
        if (! is_bool($result['suggested_damage'] ?? null)
            || (! is_int($result['max_probability_damage'] ?? null)
                && ! is_float($result['max_probability_damage'] ?? null))
            || ! is_finite((float) $result['max_probability_damage'])
            || (float) $result['max_probability_damage'] < 0
            || (float) $result['max_probability_damage'] > 1) {
            throw new VehicleDamageExecutionException('DAMAGE_OUTPUT_RESULT_INVALID');
        }

        $regions = [];
        $previousProbability = INF;
        foreach ($result['candidate_regions'] as $region) {
            if (! is_array($region)
                || ! $this->hasExactKeys($region, ['x', 'y', 'width', 'height', 'probability'])
                || ! is_int($region['x'] ?? null)
                || ! is_int($region['y'] ?? null)
                || ! is_int($region['width'] ?? null)
                || ! is_int($region['height'] ?? null)
                || $region['x'] < 0
                || $region['y'] < 0
                || $region['width'] < 1
                || $region['height'] < 1
                || $run->input_width < $region['x'] + $region['width']
                || $run->input_height < $region['y'] + $region['height']
                || (! is_int($region['probability'] ?? null) && ! is_float($region['probability'] ?? null))
                || ! is_finite((float) $region['probability'])
                || (float) $region['probability'] < (float) $run->decision_threshold
                || (float) $region['probability'] > 1
                || (float) $region['probability'] > $previousProbability + 0.000001) {
                throw new VehicleDamageExecutionException('DAMAGE_OUTPUT_RESULT_INVALID');
            }
            $previousProbability = (float) $region['probability'];
            $regions[] = [
                'x' => $region['x'],
                'y' => $region['y'],
                'width' => $region['width'],
                'height' => $region['height'],
                'probability' => (float) $region['probability'],
            ];
        }

        $maximum = (float) $result['max_probability_damage'];
        $suggested = $result['suggested_damage'];
        $expectedSuggested = $maximum >= (float) $run->decision_threshold;
        if ($suggested !== $expectedSuggested
            || ($suggested && ($regions === [] || abs($regions[0]['probability'] - $maximum) > 0.000001))
            || (! $suggested && $regions !== [])) {
            throw new VehicleDamageExecutionException('DAMAGE_OUTPUT_POLICY_MISMATCH');
        }

        return [$maximum, $suggested, $regions];
    }

    private function numericEquals(mixed $value, float $expected): bool
    {
        return (is_int($value) || is_float($value))
            && is_finite((float) $value)
            && abs((float) $value - $expected) <= 0.000001;
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
