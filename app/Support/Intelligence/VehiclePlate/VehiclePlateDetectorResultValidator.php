<?php

namespace App\Support\Intelligence\VehiclePlate;

use App\Exceptions\VehiclePlateHybridExecutionException;
use App\Models\VehiclePlatePredictionRun;
use JsonException;
use Throwable;

class VehiclePlateDetectorResultValidator
{
    public function validate(
        string $json,
        VehiclePlatePredictionRun $run,
        string $cropPath,
    ): ValidatedVehiclePlateDetection {
        try {
            $payload = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new VehiclePlateHybridExecutionException('DETECTOR_OUTPUT_JSON_INVALID');
        }
        if (! is_array($payload) || ! $this->hasExactKeys($payload, [
            'schema_version',
            'model_name',
            'architecture',
            'run_id',
            'status',
            'checkpoint_sha256',
            'threshold',
            'score',
            'bbox',
            'image',
            'detection',
            'crop',
            'timings_seconds',
            'environment',
            'safeguards',
        ])) {
            throw new VehiclePlateHybridExecutionException('DETECTOR_OUTPUT_CONTRACT_INVALID');
        }

        $status = $payload['status'] ?? null;
        $threshold = $payload['threshold'] ?? null;
        if ($payload['schema_version'] !== VehiclePlateDetectorContract::RESULT_SCHEMA_VERSION
            || $payload['model_name'] !== VehiclePlateDetectorContract::MODEL_NAME
            || $payload['architecture'] !== VehiclePlateDetectorContract::ARCHITECTURE
            || $payload['run_id'] !== $run->run_id
            || ! is_string($status)
            || ! in_array($status, VehiclePlateDetectorContract::STATUSES, true)
            || $payload['checkpoint_sha256'] !== $run->detector_checkpoint_sha256
            || (! is_int($threshold) && ! is_float($threshold))
            || ! is_finite((float) $threshold)
            || abs((float) $threshold - (float) $run->detector_threshold) > 0.0000001
            || ! $this->validImage($payload['image'] ?? null, $run)
            || ! $this->validDetectionMetadata($payload['detection'] ?? null)
            || ! $this->validTimings($payload['timings_seconds'] ?? null)
            || ! $this->validEnvironment($payload['environment'] ?? null)
            || ! $this->validSafeguards($payload['safeguards'] ?? null)) {
            throw new VehiclePlateHybridExecutionException('DETECTOR_OUTPUT_CONTRACT_INVALID');
        }

        $detection = $payload['detection'];
        $score = $payload['score'];
        $bbox = $this->validateBbox($payload['bbox'], $run, nullable: true);
        if ($status === 'no_detection') {
            if ($score !== null
                || $bbox !== null
                || $payload['crop'] !== null
                || $detection['eligible_count'] !== 0
                || $detection['ambiguous'] !== false
                || is_file($cropPath)) {
                throw new VehiclePlateHybridExecutionException('DETECTOR_OUTPUT_POLICY_MISMATCH');
            }

            return new ValidatedVehiclePlateDetection(
                status: $status,
                score: null,
                bbox: null,
                eligibleCount: 0,
                cropContents: null,
                cropBytes: null,
                cropSha256: null,
                cropWidth: null,
                cropHeight: null,
                cropBbox: null,
            );
        }

        if ((! is_int($score) && ! is_float($score))
            || ! is_finite((float) $score)
            || (float) $score < (float) $run->detector_threshold
            || (float) $score > 1
            || $bbox === null
            || $detection['eligible_count'] < 1) {
            throw new VehiclePlateHybridExecutionException('DETECTOR_OUTPUT_POLICY_MISMATCH');
        }
        if ($status === 'ambiguous') {
            if ($payload['crop'] !== null
                || $detection['eligible_count'] < 2
                || $detection['ambiguous'] !== true
                || is_file($cropPath)) {
                throw new VehiclePlateHybridExecutionException('DETECTOR_OUTPUT_POLICY_MISMATCH');
            }

            return new ValidatedVehiclePlateDetection(
                status: $status,
                score: (float) $score,
                bbox: $bbox,
                eligibleCount: $detection['eligible_count'],
                cropContents: null,
                cropBytes: null,
                cropSha256: null,
                cropWidth: null,
                cropHeight: null,
                cropBbox: null,
            );
        }
        if ($detection['ambiguous'] !== false) {
            throw new VehiclePlateHybridExecutionException('DETECTOR_OUTPUT_POLICY_MISMATCH');
        }

        return $this->validateCrop(
            $payload['crop'] ?? null,
            $cropPath,
            $status,
            (float) $score,
            $bbox,
            $detection['eligible_count'],
            $run,
        );
    }

    /** @param list<float> $bbox */
    private function validateCrop(
        mixed $crop,
        string $cropPath,
        string $status,
        float $score,
        array $bbox,
        int $eligibleCount,
        VehiclePlatePredictionRun $run,
    ): ValidatedVehiclePlateDetection {
        if (! is_array($crop) || ! $this->hasExactKeys($crop, [
            'mime', 'bytes', 'sha256', 'width', 'height', 'padding_ratio', 'bbox',
        ])
            || $crop['mime'] !== 'image/jpeg'
            || ! is_int($crop['bytes'])
            || $crop['bytes'] < 1
            || $crop['bytes'] > 2_097_152
            || ! is_string($crop['sha256'])
            || preg_match('/^[a-f0-9]{64}$/D', $crop['sha256']) !== 1
            || ! is_int($crop['width'])
            || ! is_int($crop['height'])
            || $crop['width'] < 1
            || $crop['height'] < 1
            || $crop['width'] > $run->input_width
            || $crop['height'] > $run->input_height
            || (! is_int($crop['padding_ratio']) && ! is_float($crop['padding_ratio']))
            || abs((float) $crop['padding_ratio'] - (float) $run->detector_padding_ratio) > 0.0000001) {
            throw new VehiclePlateHybridExecutionException('DETECTOR_OUTPUT_CROP_INVALID');
        }
        $cropBbox = $this->validateIntegerBbox($crop['bbox'], $run);
        if ($cropBbox === null
            || $crop['width'] !== $cropBbox[2] - $cropBbox[0]
            || $crop['height'] !== $cropBbox[3] - $cropBbox[1]
            || ! is_file($cropPath)
            || is_link($cropPath)) {
            throw new VehiclePlateHybridExecutionException('DETECTOR_OUTPUT_CROP_INVALID');
        }

        try {
            $bytes = filesize($cropPath);
            $sha256 = hash_file('sha256', $cropPath);
            $dimensions = getimagesize($cropPath);
            $contents = file_get_contents($cropPath);
        } catch (Throwable) {
            throw new VehiclePlateHybridExecutionException('DETECTOR_OUTPUT_CROP_INVALID');
        }
        if ($bytes !== $crop['bytes']
            || ! is_string($sha256)
            || ! hash_equals($crop['sha256'], $sha256)
            || ! is_array($dimensions)
            || ($dimensions['mime'] ?? null) !== 'image/jpeg'
            || ($dimensions[0] ?? null) !== $crop['width']
            || ($dimensions[1] ?? null) !== $crop['height']
            || ! is_string($contents)
            || strlen($contents) !== $crop['bytes']) {
            throw new VehiclePlateHybridExecutionException('DETECTOR_OUTPUT_CROP_INVALID');
        }

        return new ValidatedVehiclePlateDetection(
            status: $status,
            score: $score,
            bbox: $bbox,
            eligibleCount: $eligibleCount,
            cropContents: $contents,
            cropBytes: $crop['bytes'],
            cropSha256: $crop['sha256'],
            cropWidth: $crop['width'],
            cropHeight: $crop['height'],
            cropBbox: $cropBbox,
        );
    }

    private function validImage(mixed $image, VehiclePlatePredictionRun $run): bool
    {
        return is_array($image)
            && $this->hasExactKeys($image, ['width', 'height', 'sha256'])
            && $image['width'] === $run->input_width
            && $image['height'] === $run->input_height
            && is_string($image['sha256'] ?? null)
            && hash_equals((string) $run->input_sha256, $image['sha256']);
    }

    private function validDetectionMetadata(mixed $detection): bool
    {
        return is_array($detection)
            && $this->hasExactKeys($detection, ['eligible_count', 'ambiguous'])
            && is_int($detection['eligible_count'] ?? null)
            && $detection['eligible_count'] >= 0
            && $detection['eligible_count'] <= 100
            && is_bool($detection['ambiguous'] ?? null);
    }

    private function validTimings(mixed $timings): bool
    {
        if (! is_array($timings) || ! $this->hasExactKeys(
            $timings,
            ['model_load', 'inference'],
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
                'python', 'torch', 'torchvision', 'device', 'isolated_process', 'min_size', 'max_size',
            ])
            && is_string($environment['python'] ?? null)
            && is_string($environment['torch'] ?? null)
            && is_string($environment['torchvision'] ?? null)
            && in_array($environment['device'] ?? null, ['cpu', 'gpu:0'], true)
            && $environment['isolated_process'] === true
            && is_int($environment['min_size'] ?? null)
            && is_int($environment['max_size'] ?? null)
            && $environment['min_size'] >= 256
            && $environment['min_size'] <= $environment['max_size']
            && $environment['max_size'] <= 4_096;
    }

    private function validSafeguards(mixed $safeguards): bool
    {
        return is_array($safeguards)
            && $this->hasExactKeys($safeguards, [
                'development_only',
                'human_review_required',
                'automatic_vehicle_update_allowed',
                'full_frame_ocr_allowed',
            ])
            && $safeguards['development_only'] === true
            && $safeguards['human_review_required'] === true
            && $safeguards['automatic_vehicle_update_allowed'] === false
            && $safeguards['full_frame_ocr_allowed'] === false;
    }

    /** @return list<float>|null */
    private function validateBbox(
        mixed $bbox,
        VehiclePlatePredictionRun $run,
        bool $nullable,
    ): ?array {
        if ($bbox === null && $nullable) {
            return null;
        }
        if (! is_array($bbox) || ! array_is_list($bbox) || count($bbox) !== 4) {
            throw new VehiclePlateHybridExecutionException('DETECTOR_OUTPUT_BBOX_INVALID');
        }
        $values = [];
        foreach ($bbox as $value) {
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                throw new VehiclePlateHybridExecutionException('DETECTOR_OUTPUT_BBOX_INVALID');
            }
            $values[] = (float) $value;
        }
        if ($values[0] < 0
            || $values[1] < 0
            || $values[2] > $run->input_width
            || $values[3] > $run->input_height
            || $values[2] <= $values[0]
            || $values[3] <= $values[1]) {
            throw new VehiclePlateHybridExecutionException('DETECTOR_OUTPUT_BBOX_INVALID');
        }

        return $values;
    }

    /** @return list<int>|null */
    private function validateIntegerBbox(mixed $bbox, VehiclePlatePredictionRun $run): ?array
    {
        if (! is_array($bbox)
            || ! array_is_list($bbox)
            || count($bbox) !== 4
            || collect($bbox)->contains(fn ($value): bool => ! is_int($value))) {
            return null;
        }
        if ($bbox[0] < 0
            || $bbox[1] < 0
            || $bbox[2] > $run->input_width
            || $bbox[3] > $run->input_height
            || $bbox[2] <= $bbox[0]
            || $bbox[3] <= $bbox[1]) {
            return null;
        }

        return $bbox;
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
