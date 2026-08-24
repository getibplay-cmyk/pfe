<?php

namespace App\Support\Intelligence\RentalUsageAnomaly;

final readonly class ValidatedRentalUsageAnomalyOutput
{
    /**
     * @param  list<array<string, mixed>>  $budgets
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        public string $dataStatus,
        public array $budgets,
        public array $rows,
    ) {}
}
