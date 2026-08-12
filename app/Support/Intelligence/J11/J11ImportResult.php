<?php

namespace App\Support\Intelligence\J11;

use App\Models\AiAdvisoryRecordDemo;

final readonly class J11ImportResult
{
    public function __construct(
        public AiAdvisoryRecordDemo $record,
        public bool $created,
    ) {}

    public function outcome(): string
    {
        return $this->created ? 'CREATED' : 'REPLAY_SAFE';
    }
}
