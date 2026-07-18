<?php

namespace App\Actions\Insurance;

use App\Enums\InsurancePolicyStatus;
use App\Enums\TenantStatus;
use App\Models\InsurancePolicy;
use App\Models\InsurancePolicyStatusHistory;
use App\Models\Tenant;
use App\Support\Audit\AuditRecorder;
use App\Support\Insurance\InsurancePolicyTransition;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class ExpireInsurancePolicies
{
    public function __construct(private readonly TenantContext $context, private readonly AuditRecorder $audit) {}

    public function handle(): int
    {
        $expired = 0;
        Tenant::query()->where('status', TenantStatus::Active)->orderBy('id')->each(function (Tenant $tenant) use (&$expired): void {
            $this->context->run($tenant, function () use (&$expired): void {
                InsurancePolicy::query()->where('status', InsurancePolicyStatus::Active)->whereDate('ends_at', '<', today())->orderBy('id')->pluck('id')->each(function (int $policyId) use (&$expired): void {
                    DB::transaction(function () use ($policyId, &$expired): void {
                        $policy = InsurancePolicy::whereKey($policyId)->lockForUpdate()->first();
                        if (! $policy || $policy->status !== InsurancePolicyStatus::Active || ! $policy->ends_at->isBefore(today())) {
                            return;
                        }
                        InsurancePolicyTransition::allow(InsurancePolicyStatus::Active, InsurancePolicyStatus::Expired);
                        $policy->forceFill(['status' => InsurancePolicyStatus::Expired])->save();
                        InsurancePolicyStatusHistory::create(['agency_id' => $policy->agency_id, 'insurance_policy_id' => $policy->id, 'from_status' => InsurancePolicyStatus::Active, 'to_status' => InsurancePolicyStatus::Expired, 'reason' => 'Expiration automatique à la fin de couverture', 'actor_id' => null, 'changed_at' => now()]);
                        $this->audit->record('insurance.policy.expired', $policy, ['status' => 'active'], ['status' => 'expired']);
                        $expired++;
                    });
                });
            });
        });

        return $expired;
    }
}
