<?php

namespace App\Support\Intelligence\J14;

final readonly class J14DatasetSnapshotInspection
{
    /** @param list<string> $rowKeys */
    public function __construct(public array $rowKeys) {}
}
