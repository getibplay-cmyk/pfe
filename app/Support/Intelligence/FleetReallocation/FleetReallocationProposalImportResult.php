<?php

namespace App\Support\Intelligence\FleetReallocation;

use App\Models\FleetReallocationProposal;

final readonly class FleetReallocationProposalImportResult
{
    public function __construct(
        public FleetReallocationProposal $proposal,
        public bool $created,
    ) {}
}
