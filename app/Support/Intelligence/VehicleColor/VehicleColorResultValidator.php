<?php

namespace App\Support\Intelligence\VehicleColor;

use App\Exceptions\VehicleColorExecutionException;
use App\Models\VehicleColorPredictionRun;
use JsonException;

class VehicleColorResultValidator
{
    public function validate(string $json, VehicleColorPredictionRun $run): ValidatedVehicleColorResult
    {
        try {
            $payload = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new VehicleColorExecutionException('COLOR_OUTPUT_JSON_INVALID');
        }
        if (! is_array($payload) || ! $this->hasExactKeys(
            $payload,
            ['schema_version', 'model', 'input', 'result', 'safety'],
        )) {
            throw new VehicleColorExecutionException('COLOR_OUTPUT_CONTRACT_INVALID');
        }

        $model = $payload['model'] ?? null;
        $input = $payload['input'] ?? null;
        $safety = $payload['safety'] ?? null;
        if (! is_array($model)
            || ! is_array($input)
            || ! is_array($safety)
            || ! $this->hasExactKeys($model, ['name', 'version', 'artifact_sha256', 'metadata_sha256'])
            || ! $this->hasExactKeys($input, ['sha256', 'bytes', 'mime'])
            || ! $this->hasExactKeys(
                $safety,
                [
                    'mode',
                    'human_validation_required',
                    'automatic_business_action_allowed',
                    'operational_effect',
                ],
            )
            || $payload['schema_version'] !== VehicleColorContract::RESULT_SCHEMA_VERSION
            || $model['name'] !== VehicleColorContract::MODEL_NAME
            || $model['version'] !== VehicleColorContract::MODEL_VERSION
            || $model['artifact_sha256'] !== VehicleColorContract::MODEL_ARTIFACT_SHA256
            || $model['metadata_sha256'] !== VehicleColorContract::METADATA_SHA256
            || $input['sha256'] !== $run->input_sha256
            || $input['bytes'] !== $run->input_bytes
            || $input['mime'] !== $run->input_mime
            || $safety['mode'] !== VehicleColorContract::MODE
            || $safety['human_validation_required'] !== true
            || $safety['automatic_business_action_allowed'] !== false
            || $safety['operational_effect'] !== VehicleColorContract::OPERATIONAL_EFFECT) {
            throw new VehicleColorExecutionException('COLOR_OUTPUT_CONTRACT_INVALID');
        }

        $result = $payload['result'] ?? null;
        if (! is_array($result)
            || ! $this->hasExactKeys(
                $result,
                [
                    'suggested_color',
                    'confidence',
                    'accepted',
                    'top_class_index',
                    'top_class',
                    'probabilities',
                ],
            )
            || ! is_string($result['suggested_color'] ?? null)
            || ! in_array($result['suggested_color'], VehicleColorContract::SUPPORTED_COLORS, true)
            || ! is_bool($result['accepted'] ?? null)
            || ! is_int($result['top_class_index'] ?? null)
            || ! is_string($result['top_class'] ?? null)
            || ! is_array($result['probabilities'] ?? null)
            || (! is_int($result['confidence'] ?? null) && ! is_float($result['confidence'] ?? null))) {
            throw new VehicleColorExecutionException('COLOR_OUTPUT_RESULT_INVALID');
        }

        $probabilities = $this->probabilities($result['probabilities']);
        $supportedColor = $result['suggested_color'];
        $confidence = (float) $result['confidence'];
        $modelAccepted = $result['accepted'];
        $supportedValues = array_intersect_key(
            $probabilities,
            array_fill_keys(VehicleColorContract::SUPPORTED_COLORS, true),
        );
        $expectedSupportedColor = $this->firstMaximumKey($supportedValues);
        $expectedTopClass = $this->firstMaximumKey($probabilities);
        $expectedTopIndex = array_search($expectedTopClass, VehicleColorContract::CLASSES, true);
        $expectedConfidence = $supportedValues[$expectedSupportedColor];
        $expectedAccepted = $expectedTopClass !== VehicleColorContract::REJECT_CLASS
            && $expectedConfidence >= VehicleColorContract::ACCEPTED_THRESHOLD;

        if ($supportedColor !== $expectedSupportedColor
            || abs($confidence - $expectedConfidence) > 0.00001
            || $modelAccepted !== $expectedAccepted
            || $result['top_class'] !== $expectedTopClass
            || $result['top_class_index'] !== $expectedTopIndex) {
            throw new VehicleColorExecutionException('COLOR_OUTPUT_POLICY_MISMATCH');
        }

        return new ValidatedVehicleColorResult(
            suggestedColor: $supportedColor,
            confidence: $confidence,
            modelAccepted: $modelAccepted,
            probabilities: $probabilities,
        );
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, float>
     */
    private function probabilities(array $values): array
    {
        if (array_keys($values) !== VehicleColorContract::CLASSES) {
            throw new VehicleColorExecutionException('COLOR_OUTPUT_PROBABILITIES_INVALID');
        }

        $normalized = [];
        foreach ($values as $class => $value) {
            if (! is_int($value) && ! is_float($value)) {
                throw new VehicleColorExecutionException('COLOR_OUTPUT_PROBABILITIES_INVALID');
            }
            $probability = (float) $value;
            if (! is_finite($probability) || $probability < 0 || $probability > 1) {
                throw new VehicleColorExecutionException('COLOR_OUTPUT_PROBABILITIES_INVALID');
            }
            $normalized[$class] = $probability;
        }
        if (abs(array_sum($normalized) - 1.0) > 0.001) {
            throw new VehicleColorExecutionException('COLOR_OUTPUT_PROBABILITIES_INVALID');
        }

        return $normalized;
    }

    /** @param array<string, float> $values */
    private function firstMaximumKey(array $values): string
    {
        $maximum = max($values);
        foreach ($values as $key => $value) {
            if ($value === $maximum) {
                return $key;
            }
        }

        throw new VehicleColorExecutionException('COLOR_OUTPUT_PROBABILITIES_INVALID');
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
