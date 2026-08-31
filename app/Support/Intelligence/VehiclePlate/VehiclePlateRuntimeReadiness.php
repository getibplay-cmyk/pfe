<?php

namespace App\Support\Intelligence\VehiclePlate;

use App\Support\Intelligence\IntelligencePrivateStorage;

final class VehiclePlateRuntimeReadiness
{
    public function __construct(
        private readonly VehiclePlateHybridRuntime $runtime,
        private readonly VehiclePlateDetectorRuntime $detectorRuntime,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('intelligence.vehicle_plate_hybrid_review.enabled');
    }

    public function ocrReady(): bool
    {
        $sanitizer = (string) config(
            'intelligence.vehicle_plate_hybrid_review.image_sanitizer_script',
        );
        $sanitizerTimeout = (int) config(
            'intelligence.vehicle_plate_hybrid_review.image_sanitizer_timeout_seconds',
        );
        $storedDimension = (int) config(
            'intelligence.vehicle_plate_hybrid_review.max_stored_image_dimension',
        );

        return $this->enabled()
            && IntelligencePrivateStorage::configured(
                'intelligence.vehicle_plate_hybrid_review.disk',
            )
            && $this->runtime->configured()
            && is_file($sanitizer)
            && $sanitizerTimeout >= 1
            && $sanitizerTimeout <= 15
            && $storedDimension >= 256
            && $storedDimension <= 4_096;
    }

    public function ready(string $inputKind): bool
    {
        if (! VehiclePlateDetectorContract::isInputKind($inputKind)
            || ! $this->ocrReady()) {
            return false;
        }

        return $inputKind !== VehiclePlateDetectorContract::FULL_IMAGE
            || $this->detectorRuntime->ready();
    }
}
