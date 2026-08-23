<?php

namespace App\Support\Intelligence\VehicleColor;

use Throwable;

class VehicleColorModelArtifact
{
    public function configuredModelPath(): string
    {
        return (string) config('intelligence.vehicle_color_v8.model_path');
    }

    public function configuredMetadataPath(): string
    {
        return (string) config('intelligence.vehicle_color_v8.metadata_path');
    }

    public function configuredPathsArePrivate(): bool
    {
        return $this->pathIsPrivate($this->configuredModelPath())
            && $this->pathIsPrivate($this->configuredMetadataPath());
    }

    public function configuredIsValid(): bool
    {
        return $this->configuredPathsArePrivate()
            && $this->validPair(
                $this->configuredModelPath(),
                $this->configuredMetadataPath(),
            );
    }

    public function validPair(string $modelPath, string $metadataPath): bool
    {
        if (! $this->validFile(
            $modelPath,
            VehicleColorContract::MODEL_ARTIFACT_BYTES,
            VehicleColorContract::MODEL_ARTIFACT_SHA256,
        ) || ! $this->validFile(
            $metadataPath,
            VehicleColorContract::METADATA_BYTES,
            VehicleColorContract::METADATA_SHA256,
        )) {
            return false;
        }

        try {
            $metadata = json_decode(
                file_get_contents($metadataPath),
                true,
                32,
                JSON_THROW_ON_ERROR,
            );

            return is_array($metadata)
                && ($metadata['schema_version'] ?? null) === VehicleColorContract::MODEL_SCHEMA_VERSION
                && ($metadata['classes'] ?? null) === VehicleColorContract::CLASSES
                && ($metadata['supported_classes'] ?? null) === VehicleColorContract::SUPPORTED_COLORS
                && ($metadata['reject_class'] ?? null) === VehicleColorContract::REJECT_CLASS
                && ($metadata['onnx']['sha256'] ?? null) === VehicleColorContract::MODEL_ARTIFACT_SHA256
                && ($metadata['onnx']['bytes'] ?? null) === VehicleColorContract::MODEL_ARTIFACT_BYTES
                && ($metadata['calibration']['accepted_threshold'] ?? null) === VehicleColorContract::ACCEPTED_THRESHOLD
                && ($metadata['integration']['feature_flag'] ?? null) === 'RENTFLEET_COLOR_V8_ENABLED'
                && ($metadata['integration']['default'] ?? null) === false
                && ($metadata['integration']['human_validation_required'] ?? null) === true
                && ($metadata['integration']['automatic_business_action_authorized'] ?? null) === false;
        } catch (Throwable) {
            return false;
        }
    }

    private function pathIsPrivate(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);
        if ($normalized === ''
            || (str_starts_with($normalized, '/') === false
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

    private function validFile(string $path, int $bytes, string $sha256): bool
    {
        if ($path === '' || ! is_file($path) || is_link($path)) {
            return false;
        }

        try {
            $actualBytes = filesize($path);
            $actualSha256 = hash_file('sha256', $path);

            return $actualBytes === $bytes
                && is_string($actualSha256)
                && hash_equals($sha256, $actualSha256);
        } catch (Throwable) {
            return false;
        }
    }
}
