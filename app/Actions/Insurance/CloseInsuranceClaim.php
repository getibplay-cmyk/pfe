<?php

namespace App\Actions\Insurance;

use App\Actions\Documents\RequireValidCurrentDocument;
use App\Enums\DocumentType;
use App\Enums\InsuranceClaimStatus;
use App\Models\InsuranceClaim;

class CloseInsuranceClaim
{
    public function __construct(private readonly TransitionInsuranceClaim $transition, private readonly RequireValidCurrentDocument $documents) {}

    public function handle(InsuranceClaim $claim, int $actorId, ?string $note = null): InsuranceClaim
    {
        $this->documents->handle($claim, DocumentType::InsuranceClaimSettlementProof, 'document');

        return $this->transition->handle($claim, InsuranceClaimStatus::Closed, compact('note'), $actorId);
    }
}
