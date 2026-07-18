<?php

namespace App\Actions\Insurance;

use App\Enums\InsuranceClaimStatus;
use App\Enums\InsurancePolicyStatus;
use App\Models\InsuranceClaim;
use App\Models\InsurancePolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RemediateDemoInsurancePolicyProof
{
    public function __construct(private readonly AttachDemoInsurancePolicyProof $attachProof) {}

    public function handle(int $actorId): InsurancePolicy
    {
        if (app()->environment('production')) {
            $this->fail('La remédiation de démonstration est interdite en production.');
        }
        if (DB::connection()->getDatabaseName() !== 'rentfleet') {
            $this->fail('La remédiation autorisée cible uniquement la base rentfleet.');
        }

        return DB::transaction(function () use ($actorId): InsurancePolicy {
            $policy = InsurancePolicy::with(['company', 'documents.currentVersion'])
                ->whereKey(1)
                ->lockForUpdate()
                ->first();

            if (! $policy) {
                $this->fail('La police technique #1 est absente du tenant actif.');
            }
            if ($policy->tenant_id !== 1 || $policy->agency_id !== 1 || $policy->vehicle_id !== 1 || $policy->status !== InsurancePolicyStatus::Active) {
                $this->fail('Le périmètre ou le statut de la police #1 ne correspond plus à la décision approuvée.');
            }
            if ($policy->document_id !== null || $policy->documents->isNotEmpty()) {
                $this->fail('La police #1 possède désormais un document ; aucune remédiation ne sera rejouée.');
            }
            if (! $policy->company?->is_active) {
                $this->fail('La compagnie de la police #1 n’est plus active.');
            }
            if (! $policy->coverages()->lockForUpdate()->exists()) {
                $this->fail('La garantie attendue de la police #1 est absente.');
            }

            $claim = InsuranceClaim::whereKey(1)->lockForUpdate()->first();
            if (! $claim
                || $claim->tenant_id !== 1
                || $claim->agency_id !== 1
                || $claim->insurance_policy_id !== $policy->id
                || $claim->status !== InsuranceClaimStatus::UnderReview
                || $claim->reviewed_at !== null) {
                $this->fail('Le sinistre #1 ne correspond plus au scénario under_review validé.');
            }
            if ($claim->rental_contract_id !== null) {
                $contract = $claim->rentalContract()->lockForUpdate()->first();
                if (! $contract || $contract->tenant_id !== 1 || $contract->agency_id !== 1 || $contract->vehicle_id !== 1) {
                    $this->fail('Le contrat du sinistre #1 est incohérent avec la police.');
                }
            }
            if ($claim->damage_report_id !== null) {
                $damage = $claim->damageReport()->lockForUpdate()->first();
                if (! $damage || $damage->tenant_id !== 1 || $damage->agency_id !== 1 || $damage->vehicle_id !== 1 || $damage->rental_contract_id !== $claim->rental_contract_id) {
                    $this->fail('Le dommage du sinistre #1 est incohérent avec la police.');
                }
            }

            $actor = User::whereKey($actorId)->first();
            if (! $actor || $actor->tenant_id !== 1 || ! $actor->hasPermission('insurance.manage')) {
                $this->fail('L’acteur de remédiation n’est pas autorisé dans le tenant #1.');
            }

            $this->attachProof->handle($policy, $actorId, 'insurance.policy.document.remediated');

            return $policy->refresh();
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['remediation' => $message]);
    }
}
