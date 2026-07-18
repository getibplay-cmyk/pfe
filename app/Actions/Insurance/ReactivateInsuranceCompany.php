<?php

namespace App\Actions\Insurance;

use App\Models\InsuranceCompany;
use App\Support\Audit\AuditRecorder;
use App\Support\Insurance\InsuranceCompanyTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReactivateInsuranceCompany
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(InsuranceCompany $company): InsuranceCompany
    {
        return DB::transaction(function () use ($company): InsuranceCompany {
            $locked = InsuranceCompany::whereKey($company)->lockForUpdate()->firstOrFail();
            if ($locked->is_active) {
                throw ValidationException::withMessages(['company' => 'Cette compagnie est déjà active.']);
            }
            if (InsuranceCompany::query()->whereKeyNot($locked->id)->where('is_active', true)->whereRaw('lower(name) = lower(?)', [$locked->name])->exists()) {
                throw ValidationException::withMessages(['name' => 'Une compagnie active porte déjà ce nom.']);
            }
            InsuranceCompanyTransition::allow(false, true);
            $locked->forceFill(['is_active' => true, 'deactivated_at' => null, 'deactivated_by' => null])->save();
            $this->audit->record('insurance.company.reactivated', $locked, ['is_active' => false], ['is_active' => true]);

            return $locked->refresh();
        });
    }
}
