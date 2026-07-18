<?php

namespace App\Actions\Insurance;

use App\Enums\InsurancePolicyStatus;
use App\Models\InsurancePolicy;
use App\Models\InsurancePolicyStatusHistory;
use App\Support\Audit\AuditRecorder;
use App\Support\Insurance\InsurancePolicyData;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateInsurancePolicy
{
    public function __construct(private readonly InsurancePolicyData $validator, private readonly AuditRecorder $audit) {}

    public function handle(array $data, int $actorId): InsurancePolicy
    {
        if (isset($data['status']) && (string) ($data['status'] instanceof InsurancePolicyStatus ? $data['status']->value : $data['status']) !== InsurancePolicyStatus::Draft->value) {
            throw ValidationException::withMessages(['status' => 'Une police doit toujours être créée en brouillon.']);
        }
        if (! isset($data['policy_number']) || trim((string) $data['policy_number']) === '') {
            throw ValidationException::withMessages(['policy_number' => 'Le numéro de police est obligatoire.']);
        }
        $normalized = $this->validator->normalize($data);

        try {
            return DB::transaction(function () use ($normalized, $data, $actorId): InsurancePolicy {
                $policy = new InsurancePolicy([...$normalized, 'status' => InsurancePolicyStatus::Draft]);
                $policy->setPolicyNumber((string) $data['policy_number'])->save();
                InsurancePolicyStatusHistory::create(['agency_id' => $policy->agency_id, 'insurance_policy_id' => $policy->id, 'from_status' => null, 'to_status' => InsurancePolicyStatus::Draft, 'reason' => 'Création de la police', 'actor_id' => $actorId, 'changed_at' => now()]);
                $this->audit->record('insurance.policy.created', $policy, [], ['agency_id' => $policy->agency_id, 'vehicle_id' => $policy->vehicle_id, 'company_id' => $policy->insurance_company_id, 'status' => 'draft']);

                return $policy;
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw ValidationException::withMessages(['policy_number' => 'Ce numéro de police est déjà utilisé dans le tenant.']);
            }
            throw $exception;
        }
    }
}
