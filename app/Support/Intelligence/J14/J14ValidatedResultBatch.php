<?php

namespace App\Support\Intelligence\J14;

use DateTimeImmutable;

final readonly class J14ValidatedResultBatch
{
    /**
     * @param array<string, mixed> $payload
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        public array $payload,
        public array $rows,
        public string $batchId,
        public string $idempotencyKey,
        public string $canonicalPayloadSha256,
        public string $canonicalJson,
        public DateTimeImmutable $generatedAt,
    ) {}
}
