<?php

namespace App\Support\Intelligence\VehicleColor;

use App\Support\Intelligence\IntelligencePrivateStorage;

final class VehicleColorRuntimeReadiness
{
    public function __construct(
        private readonly VehicleColorModelArtifact $modelArtifact,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('intelligence.vehicle_color_v8.enabled');
    }

    public function ready(): bool
    {
        $provider = (string) config('intelligence.vehicle_color_v8.execution_provider');
        $timeout = (int) config('intelligence.vehicle_color_v8.runtime_timeout_seconds');
        $sanitizer = (string) config('intelligence.vehicle_color_v8.image_sanitizer_script');
        $sanitizerTimeout = (int) config(
            'intelligence.vehicle_color_v8.image_sanitizer_timeout_seconds',
        );
        $storedDimension = (int) config(
            'intelligence.vehicle_color_v8.max_stored_image_dimension',
        );

        return $this->enabled()
            && IntelligencePrivateStorage::configured('intelligence.vehicle_color_v8.disk')
            && $this->modelArtifact->configuredIsValid()
            && (string) config('intelligence.vehicle_color_v8.python_binary') !== ''
            && is_file((string) config('intelligence.vehicle_color_v8.runtime_script'))
            && is_file($sanitizer)
            && in_array($provider, ['CPUExecutionProvider', 'CUDAExecutionProvider'], true)
            && $timeout >= 1
            && $timeout <= 30
            && $sanitizerTimeout >= 1
            && $sanitizerTimeout <= 15
            && $storedDimension >= 256
            && $storedDimension <= 4_096;
    }
}
