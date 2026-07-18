<?php

namespace App\Actions\Insurance;

use App\Enums\InsurancePolicyStatus;
use App\Models\InsurancePolicyCoverage;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateInsuranceCoverage
{
    public function __construct(private readonly CreateInsuranceCoverage $validator, private readonly AuditRecorder $audit) {}

    public function handle(InsurancePolicyCoverage $coverage, array $data): InsurancePolicyCoverage
    {
        $values = $this->validator->values($data);

        return DB::transaction(function () use ($coverage, $values): InsurancePolicyCoverage {
            $locked = InsurancePolicyCoverage::with('policy')->whereKey($coverage)->lockForUpdate()->firstOrFail();
            if ($locked->policy->status !== InsurancePolicyStatus::Draft) {
                throw ValidationException::withMessages(['coverage' => 'Cette garantie est immuable hors du brouillon.']);
            }
            $before = $locked->only(['coverage_type', 'label', 'limit_amount', 'deductible_amount', 'terms']);
            $locked->forceFill($values)->save();
            $this->audit->record('insurance.coverage.updated', $locked, $before, $values);

            return $locked->refresh();
        });
    }
}
