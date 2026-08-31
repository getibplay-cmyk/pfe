<?php

namespace App\Support\Fleet;

final readonly class OperationalFleetReallocationSnapshot
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public array $payload,
        public string $inputFingerprint,
        public string $distanceMatrixFingerprint,
        public string $runtimeSha256,
        public string $referenceDate,
    ) {}
}
