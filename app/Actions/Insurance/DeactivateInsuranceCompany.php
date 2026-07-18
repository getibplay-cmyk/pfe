<?php

namespace App\Actions\Insurance;

use App\Models\InsuranceCompany;
use App\Support\Audit\AuditRecorder;
use App\Support\Insurance\InsuranceCompanyTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeactivateInsuranceCompany
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(InsuranceCompany $company, int $actorId): InsuranceCompany
    {
        return DB::transaction(function () use ($company, $actorId): InsuranceCompany {
            $locked = InsuranceCompany::whereKey($company)->lockForUpdate()->firstOrFail();
            if (! $locked->is_active) {
                throw ValidationException::withMessages(['company' => 'Cette compagnie est déjà inactive.']);
            }
            if ($locked->policies()->whereIn('status', ['draft', 'active'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['company' => 'La compagnie possède encore une police brouillon ou active.']);
            }
            InsuranceCompanyTransition::allow(true, false);
            $locked->forceFill(['is_active' => false, 'deactivated_at' => now(), 'deactivated_by' => $actorId])->save();
            $this->audit->record('insurance.company.deactivated', $locked, ['is_active' => true], ['is_active' => false]);

            return $locked->refresh();
        });
    }
}
