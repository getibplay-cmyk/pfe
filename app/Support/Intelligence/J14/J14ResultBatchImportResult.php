<?php

namespace App\Support\Intelligence\J14;

use App\Models\IntelligenceResultBatch;

final readonly class J14ResultBatchImportResult
{
    public function __construct(
        public IntelligenceResultBatch $batch,
        public bool $created,
    ) {}
}
