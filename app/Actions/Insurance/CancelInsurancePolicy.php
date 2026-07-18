<?php

namespace App\Actions\Insurance;

use App\Enums\InsurancePolicyStatus;
use App\Models\InsurancePolicy;
use App\Models\InsurancePolicyStatusHistory;
use App\Support\Audit\AuditRecorder;
use App\Support\Insurance\InsurancePolicyTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelInsurancePolicy
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(InsurancePolicy $policy, string $reason, int $actorId): InsurancePolicy
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Le motif d’annulation est obligatoire.']);
        }

        return DB::transaction(function () use ($policy, $reason, $actorId): InsurancePolicy {
            $locked = InsurancePolicy::whereKey($policy)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [InsurancePolicyStatus::Draft, InsurancePolicyStatus::Active], true)) {
                throw ValidationException::withMessages(['policy' => 'Cette police est terminale et ne peut plus être annulée.']);
            }
            $from = $locked->status;
            InsurancePolicyTransition::allow($from, InsurancePolicyStatus::Cancelled);
            $locked->forceFill(['status' => InsurancePolicyStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => $actorId, 'cancellation_reason' => $reason])->save();
            InsurancePolicyStatusHistory::create(['agency_id' => $locked->agency_id, 'insurance_policy_id' => $locked->id, 'from_status' => $from, 'to_status' => InsurancePolicyStatus::Cancelled, 'reason' => $reason, 'actor_id' => $actorId, 'changed_at' => now()]);
            $this->audit->record('insurance.policy.cancelled', $locked, ['status' => $from->value], ['status' => 'cancelled', 'reason' => $reason]);

            return $locked->refresh();
        });
    }
}
