<?php

namespace App\Actions\Insurance;

use App\Enums\InsuranceClaimStatus;
use App\Models\InsuranceClaim;

class StartInsuranceClaimReview
{
    public function __construct(private readonly TransitionInsuranceClaim $transition) {}

    public function handle(InsuranceClaim $claim, int $actorId, ?string $note = null): InsuranceClaim
    {
        return $this->transition->handle($claim, InsuranceClaimStatus::UnderReview, compact('note'), $actorId);
    }
}
