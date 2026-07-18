<?php

namespace App\Actions\Insurance;

use App\Enums\InsurancePolicyStatus;
use App\Models\InsurancePolicyCoverage;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ArchiveInsuranceCoverage
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(InsurancePolicyCoverage $coverage, int $actorId): void
    {
        DB::transaction(function () use ($coverage, $actorId): void {
            $locked = InsurancePolicyCoverage::with('policy')->whereKey($coverage)->lockForUpdate()->firstOrFail();
            if ($locked->policy->status !== InsurancePolicyStatus::Draft) {
                throw ValidationException::withMessages(['coverage' => 'Cette garantie est immuable hors du brouillon.']);
            }
            $locked->forceFill(['archived_by' => $actorId])->save();
            $locked->delete();
            $this->audit->record('insurance.coverage.archived', $locked, ['archived' => false], ['archived' => true]);
        });
    }
}
