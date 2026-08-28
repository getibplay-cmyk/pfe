<?php

namespace App\Support\Intelligence\VehiclePlate;

use App\Exceptions\VehiclePlateHybridExecutionException;
use JsonException;

class VehiclePlateHybridResultValidator
{
    public function validate(string $json, string $expectedCropId): ValidatedVehiclePlateHybridResult
    {
        try {
            $payload = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new VehiclePlateHybridExecutionException('PLATE_OUTPUT_JSON_INVALID');
        }
        if (! is_array($payload) || ! $this->hasExactKeys($payload, [
            'schema_version',
            'fallback_version',
            'model_name',
            'count',
            'results',
            'status_counts',
            'timings_seconds',
            'environment',
            'safeguards',
        ])) {
            throw new VehiclePlateHybridExecutionException('PLATE_OUTPUT_CONTRACT_INVALID');
        }
        if ($payload['schema_version'] !== VehiclePlateHybridContract::RESULT_SCHEMA_VERSION
            || $payload['fallback_version'] !== VehiclePlateHybridContract::FALLBACK_VERSION
            || $payload['model_name'] !== VehiclePlateHybridContract::MODEL_NAME
            || $payload['count'] !== 1
            || ! is_array($payload['results'])
            || ! array_is_list($payload['results'])
            || count($payload['results']) !== 1
            || ! $this->validSafeguards($payload['safeguards'] ?? null)
            || ! $this->validTimings($payload['timings_seconds'] ?? null)
            || ! $this->validEnvironment($payload['environment'] ?? null)
            || ! $this->validStatusCounts($payload['status_counts'] ?? null)) {
            throw new VehiclePlateHybridExecutionException('PLATE_OUTPUT_CONTRACT_INVALID');
        }

        $row = $payload['results'][0];
        if (! is_array($row) || ! $this->hasExactKeys(
            $row,
            ['crop_id', 'fallback_executed', 'suggestion', 'observations'],
        )
            || $row['crop_id'] !== $expectedCropId
            || ! is_bool($row['fallback_executed'] ?? null)
            || ! is_array($row['observations'] ?? null)
            || ! array_is_list($row['observations'])
            || count($row['observations']) < 2
            || count($row['observations']) > 32) {
            throw new VehiclePlateHybridExecutionException('PLATE_OUTPUT_ROW_INVALID');
        }
        $this->validateObservations($row['observations']);

        $suggestion = $row['suggestion'] ?? null;
        if (! is_array($suggestion) || ! $this->hasExactKeys($suggestion, [
            'schema_version',
            'status',
            'canonical',
            'display_text',
            'confidence',
            'confidence_semantics',
            'source',
            'model_name',
            'components',
            'reasons',
            'human_review_required',
            'operational_effect',
        ])) {
            throw new VehiclePlateHybridExecutionException('PLATE_OUTPUT_SUGGESTION_INVALID');
        }

        $status = $suggestion['status'] ?? null;
        $canonical = $suggestion['canonical'] ?? null;
        $displayText = $suggestion['display_text'] ?? null;
        $confidence = $suggestion['confidence'] ?? null;
        $source = $suggestion['source'] ?? null;
        if ($suggestion['schema_version'] !== VehiclePlateHybridContract::SUGGESTION_SCHEMA_VERSION
            || ! is_string($status)
            || ! in_array($status, VehiclePlateHybridContract::STATUSES, true)
            || (! is_null($canonical) && ! is_string($canonical))
            || ! is_string($displayText)
            || $displayText === ''
            || mb_strlen($displayText) > 64
            || (! is_int($confidence) && ! is_float($confidence))
            || ! is_finite((float) $confidence)
            || (float) $confidence < 0
            || (float) $confidence > 1
            || ! is_string($source)
            || ! in_array($source, ['full_crop_ppocrv5', 'segmented_ppocrv5_fusion'], true)
            || $suggestion['confidence_semantics'] !== VehiclePlateHybridContract::CONFIDENCE_SEMANTICS
            || $suggestion['model_name'] !== VehiclePlateHybridContract::MODEL_NAME
            || $suggestion['human_review_required'] !== true
            || $suggestion['operational_effect'] !== VehiclePlateHybridContract::OPERATIONAL_EFFECT
            || ! is_array($suggestion['reasons'] ?? null)
            || ! array_is_list($suggestion['reasons'])
            || count($suggestion['reasons']) > 8) {
            throw new VehiclePlateHybridExecutionException('PLATE_OUTPUT_SUGGESTION_INVALID');
        }

        $complete = in_array($status, VehiclePlateHybridContract::COMPLETE_STATUSES, true);
        $tinyCropEmpty = $status === 'empty_suggestion'
            && $row['fallback_executed'] === false
            && collect($row['observations'])->every(
                static fn (array $observation): bool => $observation['role'] === 'full',
            );
        if (($complete && (! is_string($canonical) || ! VehiclePlateHybridContract::isCanonical($canonical)))
            || (! $complete && $canonical !== null)
            || $payload['status_counts'] !== [$status => 1]
            || ($status === 'complete_primary_suggestion' && $source !== 'full_crop_ppocrv5')
            || ($status !== 'complete_primary_suggestion' && $source !== 'segmented_ppocrv5_fusion')
            || ($status === 'complete_primary_suggestion' && $row['fallback_executed'])
            || ($status !== 'complete_primary_suggestion'
                && ! $row['fallback_executed']
                && ! $tinyCropEmpty)) {
            throw new VehiclePlateHybridExecutionException('PLATE_OUTPUT_POLICY_MISMATCH');
        }

        $this->validateComponents($suggestion['components'] ?? null, $canonical);

        return new ValidatedVehiclePlateHybridResult(
            status: $status,
            canonical: $canonical,
            displayText: $displayText,
            confidence: (float) $confidence,
            source: $source,
            fallbackExecuted: $row['fallback_executed'],
        );
    }

    private function validateComponents(mixed $components, ?string $canonical): void
    {
        if (! is_array($components) || ! array_is_list($components) || count($components) > 3) {
            throw new VehiclePlateHybridExecutionException('PLATE_OUTPUT_COMPONENTS_INVALID');
        }
        $normalized = [];
        foreach ($components as $component) {
            if (! is_array($component) || ! $this->hasExactKeys($component, [
                'role', 'value', 'confidence', 'support', 'evidence', 'inferred_from_latin',
            ])
                || ! is_string($component['role'] ?? null)
                || ! in_array($component['role'], VehiclePlateHybridContract::COMPONENT_ROLES, true)
                || array_key_exists($component['role'], $normalized)
                || ! is_string($component['value'] ?? null)
                || mb_strlen($component['value']) > 5
                || (! is_int($component['confidence'] ?? null) && ! is_float($component['confidence'] ?? null))
                || ! is_finite((float) $component['confidence'])
                || (float) $component['confidence'] < 0
                || (float) $component['confidence'] > 1
                || ! is_int($component['support'] ?? null)
                || $component['support'] < 1
                || $component['support'] > 16
                || ! is_array($component['evidence'] ?? null)
                || ! array_is_list($component['evidence'])
                || count($component['evidence']) !== $component['support']
                || ! is_bool($component['inferred_from_latin'] ?? null)) {
                throw new VehiclePlateHybridExecutionException('PLATE_OUTPUT_COMPONENTS_INVALID');
            }
            foreach ($component['evidence'] as $evidence) {
                if (! is_string($evidence) || $evidence === '' || strlen($evidence) > 80) {
                    throw new VehiclePlateHybridExecutionException('PLATE_OUTPUT_COMPONENTS_INVALID');
                }
            }
            $normalized[$component['role']] = $component['value'];
        }
        if ($canonical !== null) {
            [$serial, $series, $region] = explode('|', $canonical);
            if ($normalized !== [
                'serial' => $serial,
                'series' => $series,
                'region' => $region,
            ]) {
                throw new VehiclePlateHybridExecutionException('PLATE_OUTPUT_COMPONENTS_INVALID');
            }
        }
    }

    /** @param list<mixed> $observations */
    private function validateObservations(array $observations): void
    {
        foreach ($observations as $observation) {
            if (! is_array($observation) || ! $this->hasExactKeys(
                $observation,
                ['layout_id', 'role', 'variant_id', 'raw_text', 'score'],
            )
                || ! is_string($observation['layout_id'] ?? null)
                || $observation['layout_id'] === ''
                || strlen($observation['layout_id']) > 64
                || ! is_string($observation['role'] ?? null)
                || ! in_array($observation['role'], ['full', ...VehiclePlateHybridContract::COMPONENT_ROLES], true)
                || ! is_string($observation['variant_id'] ?? null)
                || ! in_array($observation['variant_id'], ['original', 'clahe'], true)
                || ! is_string($observation['raw_text'] ?? null)
                || mb_strlen($observation['raw_text']) > 64
                || (! is_int($observation['score'] ?? null) && ! is_float($observation['score'] ?? null))
                || ! is_finite((float) $observation['score'])
                || (float) $observation['score'] < 0
                || (float) $observation['score'] > 1) {
                throw new VehiclePlateHybridExecutionException('PLATE_OUTPUT_OBSERVATIONS_INVALID');
            }
        }
    }

    private function validSafeguards(mixed $safeguards): bool
    {
        return is_array($safeguards)
            && $this->hasExactKeys($safeguards, [
                'human_review_required',
                'automatic_vehicle_update_allowed',
                'operational_effect',
                'second_ocr_model_used',
            ])
            && $safeguards['human_review_required'] === true
            && $safeguards['automatic_vehicle_update_allowed'] === false
            && $safeguards['operational_effect'] === VehiclePlateHybridContract::OPERATIONAL_EFFECT
            && $safeguards['second_ocr_model_used'] === false;
    }

    private function validTimings(mixed $timings): bool
    {
        if (! is_array($timings) || ! $this->hasExactKeys(
            $timings,
            ['ocr_load', 'ocr_inference_total'],
        )) {
            return false;
        }
        foreach ($timings as $value) {
            if ((! is_int($value) && ! is_float($value))
                || ! is_finite((float) $value)
                || (float) $value < 0) {
                return false;
            }
        }

        return true;
    }

    private function validEnvironment(mixed $environment): bool
    {
        return is_array($environment)
            && $this->hasExactKeys($environment, [
                'python',
                'paddle',
                'paddleocr',
                'paddle_cuda_compiled',
                'paddle_gpu_count',
                'device',
                'isolated_process',
            ])
            && is_string($environment['python'] ?? null)
            && is_string($environment['paddle'] ?? null)
            && is_string($environment['paddleocr'] ?? null)
            && is_bool($environment['paddle_cuda_compiled'] ?? null)
            && is_int($environment['paddle_gpu_count'] ?? null)
            && $environment['paddle_gpu_count'] >= 0
            && in_array($environment['device'] ?? null, ['cpu', 'gpu:0'], true)
            && $environment['isolated_process'] === true;
    }

    private function validStatusCounts(mixed $counts): bool
    {
        if (! is_array($counts) || count($counts) !== 1) {
            return false;
        }
        $status = array_key_first($counts);

        return is_string($status)
            && in_array($status, VehiclePlateHybridContract::STATUSES, true)
            && $counts[$status] === 1;
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
