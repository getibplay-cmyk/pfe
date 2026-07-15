<?php

namespace App\Actions\Insurance;

use App\Enums\InsuranceClaimStatus;
use App\Models\InsuranceClaim;

class SettleInsuranceClaim
{
    public function __construct(private readonly TransitionInsuranceClaim $transition) {}

    public function handle(InsuranceClaim $claim, string $amount, int $actorId, ?string $note = null): InsuranceClaim
    {
        return $this->transition->handle($claim, InsuranceClaimStatus::Settled, ['settled_amount' => $amount, 'note' => $note], $actorId);
    }
}
