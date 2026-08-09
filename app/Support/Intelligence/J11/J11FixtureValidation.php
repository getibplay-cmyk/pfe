<?php

namespace App\Support\Intelligence\J11;

final readonly class J11FixtureValidation
{
    /** @param array<string, bool> $checks */
    public function __construct(public array $checks) {}

    public function passed(): bool
    {
        return ! in_array(false, $this->checks, true);
    }

    /** @return list<string> */
    public function failedChecks(): array
    {
        return array_keys(array_filter($this->checks, fn (bool $passed): bool => ! $passed));
    }
}
