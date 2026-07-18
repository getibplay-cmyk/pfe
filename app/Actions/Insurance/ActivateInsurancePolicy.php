<?php

namespace App\Actions\Insurance;

use App\Actions\Documents\RequireValidCurrentDocument;
use App\Enums\DocumentType;
use App\Enums\InsurancePolicyStatus;
use App\Models\InsurancePolicy;
use App\Models\InsurancePolicyStatusHistory;
use App\Support\Audit\AuditRecorder;
use App\Support\Insurance\InsurancePolicyTransition;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivateInsurancePolicy
{
    public function __construct(private readonly RequireValidCurrentDocument $documents, private readonly AuditRecorder $audit) {}

    public function handle(InsurancePolicy $policy, int $actorId): InsurancePolicy
    {
        try {
            return DB::transaction(function () use ($policy, $actorId): InsurancePolicy {
                $locked = InsurancePolicy::with(['company', 'vehicle'])->whereKey($policy)->lockForUpdate()->firstOrFail();
                if ($locked->status !== InsurancePolicyStatus::Draft) {
                    throw ValidationException::withMessages(['policy' => 'Seule une police brouillon peut être activée.']);
                }
                if (! $locked->company?->is_active) {
                    throw ValidationException::withMessages(['insurance_company_id' => 'La compagnie doit être active.']);
                }
                if (! $locked->vehicle || $locked->vehicle->agency_id !== $locked->agency_id) {
                    throw ValidationException::withMessages(['vehicle_id' => 'Le véhicule n’appartient plus à l’agence de la police.']);
                }
                if ($locked->ends_at->lessThan($locked->starts_at)) {
                    throw ValidationException::withMessages(['ends_at' => 'La période de la police est invalide.']);
                }
                if (! $locked->coverages()->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['coverage' => 'Au moins une garantie active est obligatoire.']);
                }
                $document = $this->documents->handle($locked, DocumentType::InsurancePolicySigned, 'document');
                if ($document->tenant_id !== $locked->tenant_id || $document->agency_id !== $locked->agency_id) {
                    throw ValidationException::withMessages(['document' => 'Le document ne correspond pas au périmètre de la police.']);
                }
                if (InsurancePolicy::query()->whereKeyNot($locked->id)->where('vehicle_id', $locked->vehicle_id)->where('policy_type', $locked->policy_type)->where('status', InsurancePolicyStatus::Active)->whereDate('starts_at', '<=', $locked->ends_at)->whereDate('ends_at', '>=', $locked->starts_at)->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['period' => 'Une police active du même type couvre déjà ce véhicule sur cette période.']);
                }

                InsurancePolicyTransition::allow(InsurancePolicyStatus::Draft, InsurancePolicyStatus::Active);
                $locked->forceFill(['status' => InsurancePolicyStatus::Active, 'activated_at' => now(), 'activated_by' => $actorId, 'document_id' => $document->id])->save();
                InsurancePolicyStatusHistory::create(['agency_id' => $locked->agency_id, 'insurance_policy_id' => $locked->id, 'from_status' => InsurancePolicyStatus::Draft, 'to_status' => InsurancePolicyStatus::Active, 'reason' => 'Activation contrôlée', 'actor_id' => $actorId, 'changed_at' => now()]);
                $this->audit->record('insurance.policy.activated', $locked, ['status' => 'draft'], ['status' => 'active', 'document_id' => $document->id]);

                return $locked->refresh();
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23P01') {
                throw ValidationException::withMessages(['period' => 'Une police active du même type couvre déjà ce véhicule sur cette période.']);
            }
            throw $exception;
        }
    }
}
