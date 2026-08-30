<?php

namespace App\Support\Fleet;

final readonly class AgencyDistanceMatrixResult
{
    /**
     * @param  array<int, array<int, string>>  $matrix
     * @param  list<array{from_agency_id:int,to_agency_id:int}>  $missingPairs
     */
    public function __construct(
        public string $status,
        public array $matrix,
        public array $missingPairs,
        public ?string $fingerprint,
        public ?string $reason = null,
    ) {}

    public function complete(): bool
    {
        return $this->status === 'complete';
    }
}
