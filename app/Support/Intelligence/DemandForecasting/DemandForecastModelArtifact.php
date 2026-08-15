<?php

namespace App\Support\Intelligence\DemandForecasting;

use Throwable;

class DemandForecastModelArtifact
{
    public function configuredPath(): string
    {
        return (string) config('intelligence.demand_forecasting.model_bundle_path');
    }

    public function configuredIsValid(): bool
    {
        return $this->configuredPathIsPrivate() && $this->valid($this->configuredPath());
    }

    public function configuredPathIsPrivate(): bool
    {
        $path = str_replace('\\', '/', $this->configuredPath());
        if ($path === ''
            || (str_starts_with($path, '/') === false
                && preg_match('/^[A-Za-z]:\//D', $path) !== 1)
            || str_contains($path, '/../')
            || str_ends_with($path, '/..')) {
            return false;
        }

        $public = rtrim(str_replace('\\', '/', public_path()), '/').'/';

        return ! str_starts_with(mb_strtolower($path), mb_strtolower($public));
    }

    public function valid(string $path): bool
    {
        if ($path === '' || ! is_file($path) || is_link($path)) {
            return false;
        }

        try {
            $bytes = filesize($path);
            if ($bytes !== DemandForecastContract::MODEL_ARTIFACT_BYTES) {
                return false;
            }

            $digest = hash_file('sha256', $path);

            return is_string($digest)
                && hash_equals(DemandForecastContract::MODEL_ARTIFACT_SHA256, $digest);
        } catch (Throwable) {
            return false;
        }
    }
}
