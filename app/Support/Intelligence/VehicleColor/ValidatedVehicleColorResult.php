<?php

namespace App\Support\Intelligence\VehicleColor;

final readonly class ValidatedVehicleColorResult
{
    /** @param array<string, float> $probabilities */
    public function __construct(
        public string $suggestedColor,
        public float $confidence,
        public bool $modelAccepted,
        public array $probabilities,
    ) {}
}
