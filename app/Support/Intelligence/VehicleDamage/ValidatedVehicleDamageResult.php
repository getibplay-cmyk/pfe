<?php

namespace App\Support\Intelligence\VehicleDamage;

final readonly class ValidatedVehicleDamageResult
{
    /**
     * @param  list<string>  $qualityReasons
     * @param  array{brightness: float, contrast: float, sharpness: float}  $qualityMetrics
     * @param  list<array{x: int, y: int, width: int, height: int, probability: float}>  $candidateRegions
     */
    public function __construct(
        public string $qualityStatus,
        public array $qualityReasons,
        public array $qualityMetrics,
        public int $evaluatedPatches,
        public ?float $maxProbabilityDamage,
        public ?bool $suggestedDamage,
        public array $candidateRegions,
    ) {}
}
