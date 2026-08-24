<?php

namespace App\Support\Intelligence\RentalUsageAnomaly;

final readonly class RentalUsageAnomalySnapshotInspection
{
    /**
     * @param  array<string, array<string, string>>  $rowsById
     */
    public function __construct(public array $rowsById) {}
}
