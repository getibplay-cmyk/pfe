<?php

namespace App\Support\Intelligence\FleetReallocation;

use DateTimeImmutable;

final readonly class ValidatedFleetReallocationProposal
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $moves
     */
    public function __construct(
        public array $payload,
        public array $moves,
        public string $proposalId,
        public string $idempotencyKey,
        public string $canonicalPayloadSha256,
        public string $canonicalJson,
        public DateTimeImmutable $generatedAt,
        public DateTimeImmutable $asOfDate,
        public DateTimeImmutable $targetDate,
    ) {}
}
