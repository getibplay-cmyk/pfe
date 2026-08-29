<?php

namespace App\Support\Intelligence\VehiclePlate;

final readonly class ValidatedVehiclePlateHybridResult
{
    public function __construct(
        public string $status,
        public ?string $canonical,
        public string $displayText,
        public float $confidence,
        public string $source,
        public bool $fallbackExecuted,
    ) {}
}
