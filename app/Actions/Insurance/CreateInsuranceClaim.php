<?php

namespace App\Actions\Insurance;

use App\Actions\Rentals\GenerateBusinessNumber;
use App\Enums\InsuranceClaimStatus;
use App\Enums\InsurancePolicyStatus;
use App\Models\DamageReport;
use App\Models\InsuranceClaim;
use App\Models\InsuranceClaimStatusHistory;
use App\Models\InsurancePolicy;
use App\Models\RentalContract;
use App\Support\Audit\AuditRecorder;
use App\Support\Pricing\DecimalMoney;
use App\Support\Tenancy\AgencyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateInsuranceClaim
{
    public function __construct(
        private readonly GenerateBusinessNumber $numbers,
        private readonly AgencyAccess $agencyAccess,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(array $data, int $actorId): InsuranceClaim
    {
        $requestedStatus = $data['status'] ?? InsuranceClaimStatus::Reported->value;
        $requestedStatus = $requestedStatus instanceof InsuranceClaimStatus ? $requestedStatus : InsuranceClaimStatus::tryFrom((string) $requestedStatus);
        if ($requestedStatus !== InsuranceClaimStatus::Reported) {
            throw ValidationException::withMessages(['status' => 'Un sinistre doit toujours être créé à l’état déclaré.']);
        }
        foreach (['approved_amount', 'settled_amount'] as $decisionAmount) {
            if (array_key_exists($decisionAmount, $data) && $data[$decisionAmount] !== null && $data[$decisionAmount] !== '') {
                throw ValidationException::withMessages([$decisionAmount => 'Ce montant ne peut être renseigné qu’au cours de la transition correspondante.']);
            }
        }

        $data['agency_id'] = $this->agencyAccess->required($data['agency_id'] ?? null);
        $policy = InsurancePolicy::findOrFail($data['insurance_policy_id']);

        if ($policy->agency_id !== $data['agency_id']) {
            throw ValidationException::withMessages(['insurance_policy_id' => 'Police incompatible avec cette agence.']);
        }
        if ($policy->status === InsurancePolicyStatus::Draft) {
            throw ValidationException::withMessages(['insurance_policy_id' => 'Un sinistre ne peut pas être rattaché à une police brouillon.']);
        }

        if (! isset($data['incident_at'])) {
            throw ValidationException::withMessages(['incident_at' => 'La date réelle de l’incident est obligatoire.']);
        }
        try {
            $incidentAt = CarbonImmutable::parse($data['incident_at']);
            $reportedAt = isset($data['reported_at']) ? CarbonImmutable::parse($data['reported_at']) : CarbonImmutable::now();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['incident_at' => 'La date de l’incident est invalide.']);
        }
        if ($incidentAt->greaterThan($reportedAt)) {
            throw ValidationException::withMessages(['incident_at' => 'L’incident ne peut pas être postérieur à sa déclaration.']);
        }
        if ($incidentAt->toDateString() < $policy->starts_at->toDateString() || $incidentAt->toDateString() > $policy->ends_at->toDateString()) {
            throw ValidationException::withMessages(['incident_at' => 'L’incident doit appartenir à la période de couverture.']);
        }
        if ($policy->status === InsurancePolicyStatus::Cancelled && $policy->cancelled_at && $incidentAt->greaterThan($policy->cancelled_at)) {
            throw ValidationException::withMessages(['incident_at' => 'L’incident est postérieur à l’annulation de la police.']);
        }
        $data['incident_at'] = $incidentAt;
        $data['reported_at'] = $reportedAt;

        $contract = $this->contract($data['rental_contract_id'] ?? null);
        $damage = $this->damage($data['damage_report_id'] ?? null);

        if ($damage && ! $contract) {
            $contract = RentalContract::findOrFail($damage->rental_contract_id);
            $data['rental_contract_id'] = $contract->id;
        }

        if ($contract && ($contract->agency_id !== $data['agency_id'] || $contract->vehicle_id !== $policy->vehicle_id)) {
            throw ValidationException::withMessages(['rental_contract_id' => 'Contrat incompatible avec la police et cette agence.']);
        }

        if ($damage && ($damage->agency_id !== $data['agency_id'] || $damage->vehicle_id !== $policy->vehicle_id || $damage->rental_contract_id !== $contract?->id)) {
            throw ValidationException::withMessages(['damage_report_id' => 'Dommage incompatible avec la police, le contrat ou cette agence.']);
        }

        foreach (['claimed_amount'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = DecimalMoney::fromMinorUnits(DecimalMoney::toMinorUnits($data[$field]));
            }
        }

        $data['insurer_reference_encrypted'] = $data['insurer_reference'] ?? null;
        unset($data['insurer_reference']);

        unset($data['status'], $data['approved_amount'], $data['settled_amount']);

        return DB::transaction(function () use ($data, $actorId) {
            $claim = InsuranceClaim::create([
                ...$data,
                'status' => InsuranceClaimStatus::Reported,
                'reported_at' => $data['reported_at'] ?? now(),
                'claim_number' => $this->numbers->handle('claim'),
                'created_by' => $actorId,
            ]);
            InsuranceClaimStatusHistory::create([
                'agency_id' => $claim->agency_id,
                'insurance_claim_id' => $claim->id,
                'from_status' => null,
                'to_status' => InsuranceClaimStatus::Reported,
                'actor_id' => $actorId,
                'note' => 'Déclaration initiale',
                'changed_at' => $claim->reported_at,
            ]);
            $this->audit->record('insurance_claim.reported', $claim, [], ['status' => InsuranceClaimStatus::Reported->value, 'claimed_amount' => $claim->claimed_amount]);

            return $claim;
        });
    }

    private function contract(mixed $contractId): ?RentalContract
    {
        if ($contractId === null || $contractId === '') {
            return null;
        }

        return RentalContract::find($contractId)
            ?? throw ValidationException::withMessages(['rental_contract_id' => 'Contrat introuvable dans le tenant actif.']);
    }

    private function damage(mixed $damageId): ?DamageReport
    {
        if ($damageId === null || $damageId === '') {
            return null;
        }

        return DamageReport::find($damageId)
            ?? throw ValidationException::withMessages(['damage_report_id' => 'Dommage introuvable dans le tenant actif.']);
    }
}
