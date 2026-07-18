<?php

namespace App\Actions\Insurance;

use App\Enums\InsurancePolicyStatus;
use App\Models\InsurancePolicy;
use App\Support\Audit\AuditRecorder;
use App\Support\Insurance\InsurancePolicyData;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateDraftInsurancePolicy
{
    public function __construct(private readonly InsurancePolicyData $validator, private readonly AuditRecorder $audit) {}

    public function handle(InsurancePolicy $policy, array $data): InsurancePolicy
    {
        return DB::transaction(function () use ($policy, $data): InsurancePolicy {
            $locked = InsurancePolicy::whereKey($policy)->lockForUpdate()->firstOrFail();
            if ($locked->status !== InsurancePolicyStatus::Draft) {
                throw ValidationException::withMessages(['policy' => 'Seule une police brouillon peut être modifiée.']);
            }
            if (isset($data['status'])) {
                throw ValidationException::withMessages(['status' => 'Le statut ne peut être modifié que par une action de transition.']);
            }
            $normalized = $this->validator->normalize($data, $locked->agency_id);
            $before = $this->values($locked);
            $locked->forceFill($normalized);
            if (isset($data['policy_number']) && trim((string) $data['policy_number']) !== '') {
                $locked->setPolicyNumber((string) $data['policy_number']);
            }
            $locked->save();
            $this->audit->record('insurance.policy.updated', $locked, $before, $this->values($locked));

            return $locked->refresh();
        });
    }

    private function values(InsurancePolicy $policy): array
    {
        return ['vehicle_id' => $policy->vehicle_id, 'company_id' => $policy->insurance_company_id, 'policy_type' => $policy->policy_type, 'starts_at' => $policy->starts_at?->toDateString(), 'ends_at' => $policy->ends_at?->toDateString(), 'premium_amount' => $policy->premium_amount, 'deductible_amount' => $policy->deductible_amount, 'currency' => $policy->currency];
    }
}
