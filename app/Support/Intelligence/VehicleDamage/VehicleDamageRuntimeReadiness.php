<?php

namespace App\Support\Intelligence\VehicleDamage;

use App\Support\Intelligence\IntelligencePrivateStorage;

final class VehicleDamageRuntimeReadiness
{
    public function __construct(private readonly VehicleDamageModelArtifact $modelArtifact) {}

    public function ready(): bool
    {
        $provider = (string) config('intelligence.vehicle_damage_v1.execution_provider');
        $timeout = (int) config('intelligence.vehicle_damage_v1.runtime_timeout_seconds');
        $sanitizerTimeout = (int) config(
            'intelligence.vehicle_damage_v1.image_sanitizer_timeout_seconds',
        );
        $storedDimension = (int) config(
            'intelligence.vehicle_damage_v1.max_stored_image_dimension',
        );
        $maxPatches = (int) config('intelligence.vehicle_damage_v1.max_scan_patches');

        return (bool) config('intelligence.vehicle_damage_v1.enabled')
            && IntelligencePrivateStorage::configured('intelligence.vehicle_damage_v1.disk')
            && $this->modelArtifact->configuredIsValid()
            && (string) config('intelligence.vehicle_damage_v1.python_binary') !== ''
            && is_file((string) config('intelligence.vehicle_damage_v1.runtime_script'))
            && is_file((string) config('intelligence.vehicle_damage_v1.image_sanitizer_script'))
            && in_array($provider, ['CPUExecutionProvider', 'CUDAExecutionProvider'], true)
            && $timeout >= 10
            && $timeout <= 120
            && $sanitizerTimeout >= 1
            && $sanitizerTimeout <= 15
            && $storedDimension >= 384
            && $storedDimension <= 4_096
            && $maxPatches >= 1
            && $maxPatches <= 64;
    }
}
