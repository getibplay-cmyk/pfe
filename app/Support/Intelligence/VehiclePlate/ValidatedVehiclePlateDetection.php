<?php

namespace App\Support\Intelligence\VehiclePlate;

final readonly class ValidatedVehiclePlateDetection
{
    /**
     * @param  list<float>|null  $bbox
     * @param  list<int>|null  $cropBbox
     */
    public function __construct(
        public string $status,
        public ?float $score,
        public ?array $bbox,
        public int $eligibleCount,
        public ?string $cropContents,
        public ?int $cropBytes,
        public ?string $cropSha256,
        public ?int $cropWidth,
        public ?int $cropHeight,
        public ?array $cropBbox,
    ) {}

    public function detected(): bool
    {
        return $this->status === 'detected';
    }
}
