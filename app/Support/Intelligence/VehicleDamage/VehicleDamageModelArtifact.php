<?php

namespace App\Support\Intelligence\VehicleDamage;

use Throwable;

class VehicleDamageModelArtifact
{
    public function configuredModelPath(): string
    {
        return (string) config('intelligence.vehicle_damage_v1.model_path');
    }

    public function configuredModelCardPath(): string
    {
        return (string) config('intelligence.vehicle_damage_v1.model_card_path');
    }

    public function configuredModelSha256(): string
    {
        return mb_strtolower((string) config('intelligence.vehicle_damage_v1.model_sha256'));
    }

    public function configuredModelCardSha256(): string
    {
        return mb_strtolower((string) config('intelligence.vehicle_damage_v1.model_card_sha256'));
    }

    public function configuredPathsArePrivate(): bool
    {
        return $this->pathIsPrivate($this->configuredModelPath())
            && $this->pathIsPrivate($this->configuredModelCardPath());
    }

    public function configuredIsValid(): bool
    {
        return $this->configuredPathsArePrivate()
            && $this->validPair(
                $this->configuredModelPath(),
                $this->configuredModelCardPath(),
            );
    }

    public function validPair(string $modelPath, string $modelCardPath): bool
    {
        $modelSha256 = $this->configuredModelSha256();
        $cardSha256 = $this->configuredModelCardSha256();
        if (! $this->validSha256($modelSha256)
            || ! $this->validSha256($cardSha256)
            || ! $this->validFile($modelPath, $modelSha256, 1_000_000, 536_870_912)
            || ! $this->validFile($modelCardPath, $cardSha256, 100, 65_536)) {
            return false;
        }

        try {
            $card = json_decode(
                (string) file_get_contents($modelCardPath),
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
            $input = is_array($card) ? ($card['input'] ?? null) : null;
            $classes = is_array($card) ? ($card['classes'] ?? null) : null;
            $gate = is_array($card) ? ($card['release_gate'] ?? null) : null;
            $threshold = is_array($card) ? ($card['decision_threshold'] ?? null) : null;

            if (! is_array($card)
                || ($card['model_id'] ?? null) !== VehicleDamageContract::modelCardId()
                || (! is_int($threshold) && ! is_float($threshold))
                || abs((float) $threshold - VehicleDamageContract::decisionThreshold()) > 0.000001) {
                return false;
            }

            if (VehicleDamageContract::backend() === VehicleDamageContract::BACKEND_RTDETRV2_S) {
                return $this->validRtDetrCard($card, $modelSha256);
            }

            return ($card['model_id'] ?? null) === VehicleDamageContract::LEGACY_MODEL_CARD_ID
                && ($card['task'] ?? null) === 'binary_consultative_vehicle_damage_presence'
                && ($card['architecture'] ?? null) === 'torchvision.efficientnet_v2_s'
                && is_array($classes)
                && ($classes[0] ?? null) === 'aucun_dommage_visible'
                && ($classes[1] ?? null) === 'dommage_visible'
                && is_array($input)
                && ($input['color'] ?? null) === 'RGB'
                && ($input['resize'] ?? null) === 384
                && ($input['crop'] ?? null) === 384
                && $this->numericListEquals($input['mean'] ?? null, [0.485, 0.456, 0.406])
                && $this->numericListEquals($input['std'] ?? null, [0.229, 0.224, 0.225])
                && is_array($gate)
                && ($gate['passed'] ?? null) === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function validRtDetrCard(array $card, string $modelSha256): bool
    {
        $input = $card['input'] ?? null;
        $postprocess = $card['postprocess'] ?? null;
        $source = $card['source_checkpoint'] ?? null;
        $validation = $card['validation'] ?? null;
        $gate = $card['scientific_gate'] ?? null;
        $safety = $card['safety'] ?? null;

        return ($card['model_name'] ?? null) === VehicleDamageContract::MODEL_NAME
            && ($card['model_version'] ?? null) === VehicleDamageContract::MODEL_VERSION
            && ($card['task'] ?? null) === 'consultative_vehicle_damage_detection'
            && ($card['architecture'] ?? null) === 'rtdetrv2_r18vd'
            && ($card['classes'] ?? null) === ['0' => 'dommage_visible']
            && ($card['onnx_sha256'] ?? null) === $modelSha256
            && is_array($input)
            && ($input['images_name'] ?? null) === 'images'
            && ($input['orig_target_sizes_name'] ?? null) === 'orig_target_sizes'
            && ($input['color'] ?? null) === 'RGB'
            && ($input['resize'] ?? null) === VehicleDamageContract::INPUT_SIZE
            && ($input['normalization'] ?? null) === 'zero_one'
            && ($card['outputs'] ?? null) === ['labels', 'boxes', 'scores']
            && is_array($postprocess)
            && ($postprocess['type'] ?? null) === 'hard_nms'
            && ($postprocess['class_agnostic'] ?? null) === true
            && $this->numericEquals(
                $postprocess['iou_threshold'] ?? null,
                VehicleDamageContract::NMS_IOU_THRESHOLD,
            )
            && ($postprocess['max_candidates'] ?? null) === VehicleDamageContract::MAX_CANDIDATES
            && is_array($source)
            && ($source['filename'] ?? null) === 'selected_checkpoint_soup_19_24_29_inference_only.pth'
            && ($source['sha256'] ?? null) === VehicleDamageContract::SOURCE_CHECKPOINT_SHA256
            && ($source['epochs'] ?? null) === [19, 24, 29]
            && $this->numericListEquals($source['weights'] ?? null, [0.25, 0.5, 0.25])
            && is_array($validation)
            && $this->numericEquals($validation['AP'] ?? null, VehicleDamageContract::VALIDATION_AP)
            && $this->numericEquals($validation['AP50'] ?? null, VehicleDamageContract::VALIDATION_AP50)
            && $this->numericEquals($validation['AP75'] ?? null, VehicleDamageContract::VALIDATION_AP75)
            && ($validation['operating_profile'] ?? null) === 'precision_90'
            && $this->numericEquals(
                $validation['precision_iou50'] ?? null,
                VehicleDamageContract::VALIDATION_PRECISION_IOU50,
            )
            && $this->numericEquals(
                $validation['recall_iou50'] ?? null,
                VehicleDamageContract::VALIDATION_RECALL_IOU50,
            )
            && ($validation['tuned_on_validation'] ?? null) === true
            && is_array($gate)
            && $this->numericEquals($gate['AP'] ?? null, 0.40)
            && $this->numericEquals($gate['AP50'] ?? null, 0.65)
            && ($gate['passed'] ?? null) === false
            && is_array($safety)
            && ($safety['human_review_required'] ?? null) === true
            && ($safety['automatic_business_action_allowed'] ?? null) === false
            && ($safety['final_test_sealed'] ?? null) === true
            && ($safety['calibration_used'] ?? null) === false
            && ($safety['test_used'] ?? null) === false
            && ($safety['local_pilot_required'] ?? null) === true;
    }

    private function pathIsPrivate(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);
        if ($normalized === ''
            || (! str_starts_with($normalized, '/')
                && preg_match('/^[A-Za-z]:\//D', $normalized) !== 1)
            || str_contains($normalized, '/../')
            || str_ends_with($normalized, '/..')) {
            return false;
        }

        $public = rtrim(str_replace('\\', '/', (string) realpath(public_path())), '/').'/';
        $resolved = realpath($path);
        if (is_string($resolved)) {
            $normalized = str_replace('\\', '/', $resolved);
        }

        return ! str_starts_with(mb_strtolower($normalized), mb_strtolower($public));
    }

    private function validFile(string $path, string $sha256, int $minimumBytes, int $maximumBytes): bool
    {
        if ($path === '' || ! is_file($path) || is_link($path)) {
            return false;
        }

        try {
            $bytes = filesize($path);
            $actualSha256 = hash_file('sha256', $path);

            return is_int($bytes)
                && $bytes >= $minimumBytes
                && $bytes <= $maximumBytes
                && is_string($actualSha256)
                && hash_equals($sha256, $actualSha256);
        } catch (Throwable) {
            return false;
        }
    }

    private function validSha256(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private function numericEquals(mixed $actual, float $expected): bool
    {
        return (is_int($actual) || is_float($actual))
            && is_finite((float) $actual)
            && abs((float) $actual - $expected) <= 0.000001;
    }

    /** @param list<float> $expected */
    private function numericListEquals(mixed $actual, array $expected): bool
    {
        if (! is_array($actual) || count($actual) !== count($expected)) {
            return false;
        }
        foreach ($expected as $index => $value) {
            if ((! is_int($actual[$index] ?? null) && ! is_float($actual[$index] ?? null))
                || abs((float) $actual[$index] - $value) > 0.000001) {
                return false;
            }
        }

        return true;
    }
}
