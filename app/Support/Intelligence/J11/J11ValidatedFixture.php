<?php

namespace App\Support\Intelligence\J11;

use App\Enums\J11AdvisoryModule;

final readonly class J11ValidatedFixture
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public J11AdvisoryModule $module,
        public string $recordId,
        public string $idempotencyKey,
        public string $fingerprint,
        public array $payload,
    ) {}
}
