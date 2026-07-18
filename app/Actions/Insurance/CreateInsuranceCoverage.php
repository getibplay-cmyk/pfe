<?php

namespace App\Actions\Insurance;

use App\Enums\InsurancePolicyStatus;
use App\Models\InsurancePolicy;
use App\Models\InsurancePolicyCoverage;
use App\Support\Audit\AuditRecorder;
use App\Support\Pricing\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateInsuranceCoverage
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(InsurancePolicy $policy, array $data): InsurancePolicyCoverage
    {
        $values = $this->values($data);

        return DB::transaction(function () use ($policy, $values): InsurancePolicyCoverage {
            $locked = InsurancePolicy::whereKey($policy)->lockForUpdate()->firstOrFail();
            if ($locked->status !== InsurancePolicyStatus::Draft) {
                throw ValidationException::withMessages(['coverage' => 'Les garanties sont modifiables uniquement lorsque la police est brouillon.']);
            }
            $coverage = $locked->coverages()->create($values);
            $this->audit->record('insurance.coverage.created', $coverage, [], $values);

            return $coverage;
        });
    }

    public function values(array $data): array
    {
        if (! in_array($data['coverage_type'] ?? null, ['liability', 'collision', 'theft', 'fire', 'glass', 'assistance', 'legal_defence', 'other'], true) || trim((string) ($data['label'] ?? '')) === '') {
            throw ValidationException::withMessages(['coverage' => 'Le type et le libellé de garantie sont obligatoires.']);
        }
        try {
            $limit = ($data['limit_amount'] ?? '') === '' ? null : DecimalMoney::fromMinorUnits(DecimalMoney::toMinorUnits($data['limit_amount']));
            $deductible = ($data['deductible_amount'] ?? '') === '' ? null : DecimalMoney::fromMinorUnits(DecimalMoney::toMinorUnits($data['deductible_amount']));
        } catch (\Throwable) {
            throw ValidationException::withMessages(['coverage' => 'Les montants de garantie sont invalides.']);
        }

        return ['coverage_type' => $data['coverage_type'], 'label' => trim($data['label']), 'limit_amount' => $limit, 'deductible_amount' => $deductible, 'terms' => $data['terms'] ?? []];
    }
}
