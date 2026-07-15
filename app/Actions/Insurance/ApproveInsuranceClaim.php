<?php

namespace App\Actions\Insurance;

use App\Enums\InsuranceClaimStatus;
use App\Models\InsuranceClaim;

class ApproveInsuranceClaim
{
    public function __construct(private readonly TransitionInsuranceClaim $transition) {}

    public function handle(InsuranceClaim $claim, string $amount, int $actorId, ?string $note = null): InsuranceClaim
    {
        return $this->transition->handle($claim, InsuranceClaimStatus::Approved, ['approved_amount' => $amount, 'note' => $note], $actorId);
    }
}
