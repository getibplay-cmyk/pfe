<?php

namespace App\Support\Intelligence\DemandForecasting;

use DateTimeImmutable;

final readonly class ValidatedDemandForecastBatch
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $forecasts
     */
    public function __construct(
        public array $payload,
        public array $forecasts,
        public string $batchId,
        public string $idempotencyKey,
        public string $canonicalPayloadSha256,
        public string $canonicalJson,
        public DateTimeImmutable $generatedAt,
    ) {}
}
