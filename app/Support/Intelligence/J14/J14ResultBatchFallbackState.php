<?php

namespace App\Support\Intelligence\J14;

use App\Models\IntelligenceResultBatch;

final readonly class J14ResultBatchFallbackState
{
    public function __construct(
        public ?IntelligenceResultBatch $batch,
        public ?int $ageSeconds,
    ) {}

    public function available(): bool
    {
        return $this->batch !== null;
    }
}
