<?php

namespace App\Actions\Insurance;

use App\Enums\InsurancePolicyStatus;
use App\Models\InsurancePolicy;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RenewInsurancePolicy
{
    public function __construct(private readonly CreateInsurancePolicy $createPolicy, private readonly AuditRecorder $audit) {}

    public function handle(InsurancePolicy $policy, array $data, int $actorId): InsurancePolicy
    {
        return DB::transaction(function () use ($policy, $data, $actorId): InsurancePolicy {
            $locked = InsurancePolicy::whereKey($policy)->lockForUpdate()->firstOrFail();
            if ($locked->status === InsurancePolicyStatus::Draft) {
                throw ValidationException::withMessages(['policy' => 'Une police brouillon doit être modifiée, pas renouvelée.']);
            }
            if (! isset($data['policy_number'], $data['starts_at'], $data['ends_at'])) {
                throw ValidationException::withMessages(['renewal' => 'Un nouveau numéro et une nouvelle période sont obligatoires.']);
            }
            $new = $this->createPolicy->handle([
                'agency_id' => $locked->agency_id,
                'vehicle_id' => $locked->vehicle_id,
                'insurance_company_id' => $locked->insurance_company_id,
                'policy_number' => $data['policy_number'],
                'policy_type' => $locked->policy_type,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'premium_amount' => $data['premium_amount'] ?? $locked->premium_amount,
                'deductible_amount' => $data['deductible_amount'] ?? $locked->deductible_amount,
                'currency' => $locked->currency,
            ], $actorId);
            $new->forceFill(['renewed_from_id' => $locked->id])->save();
            $copied = 0;
            if (($data['copy_coverages'] ?? false) === true) {
                foreach ($locked->coverages()->get() as $coverage) {
                    $new->coverages()->create(['coverage_type' => $coverage->coverage_type, 'label' => $coverage->label, 'limit_amount' => $coverage->limit_amount, 'deductible_amount' => $coverage->deductible_amount, 'terms' => $coverage->terms]);
                    $copied++;
                }
            }
            $this->audit->record('insurance.policy.renewed', $new, [], ['renewed_from_id' => $locked->id, 'status' => 'draft', 'coverages_copied' => $copied]);

            return $new->refresh();
        });
    }
}
