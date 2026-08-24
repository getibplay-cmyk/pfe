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

            return is_array($card)
                && ($card['model_id'] ?? null) === VehicleDamageContract::MODEL_CARD_ID
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
                && (is_int($threshold) || is_float($threshold))
                && abs((float) $threshold - VehicleDamageContract::DECISION_THRESHOLD) <= 0.000001
                && is_array($gate)
                && ($gate['passed'] ?? null) === true;
        } catch (Throwable) {
            return false;
        }
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
