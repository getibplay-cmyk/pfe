<?php

namespace App\Support\Intelligence\VehiclePlate;

use Throwable;

class VehiclePlateDetectorArtifact
{
    public function configuredPath(): string
    {
        return (string) config('intelligence.vehicle_plate_hybrid_review.detector.model_path');
    }

    public function configuredSha256(): string
    {
        return mb_strtolower((string) config(
            'intelligence.vehicle_plate_hybrid_review.detector.model_sha256',
        ));
    }

    public function configuredPathIsPrivate(): bool
    {
        return $this->pathIsPrivate($this->configuredPath());
    }

    public function configuredIsValid(): bool
    {
        return $this->configuredPathIsPrivate()
            && $this->validFile($this->configuredPath(), $this->configuredSha256());
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

    private function validFile(string $path, string $sha256): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1
            || $path === ''
            || ! is_file($path)
            || is_link($path)) {
            return false;
        }

        try {
            $bytes = filesize($path);
            $actualSha256 = hash_file('sha256', $path);

            return is_int($bytes)
                && $bytes >= 1_000_000
                && $bytes <= 2_147_483_648
                && is_string($actualSha256)
                && hash_equals($sha256, $actualSha256);
        } catch (Throwable) {
            return false;
        }
    }
}
