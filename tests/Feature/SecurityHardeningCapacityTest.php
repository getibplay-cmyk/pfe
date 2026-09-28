<?php

namespace Tests\Feature;

use App\Models\FleetReallocationRun;
use App\Support\Security\IntelligenceCapacity;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SecurityHardeningCapacityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_full_tenant_cannot_admit_more_work_and_does_not_consume_another_tenants_quota(): void
    {
        config(['security.intelligence.tenant_pending' => 1, 'security.intelligence.global_pending' => 2]);
        $ownerA = $this->createTenantOwner();
        $ownerB = $this->createTenantOwner();
        $context = app(TenantContext::class);
        $context->run($ownerA->tenant_id, fn () => FleetReallocationRun::create([
            'run_id' => (string) Str::uuid(), 'requested_by' => $ownerA->id,
            'forecast_horizon' => 1, 'scenario_number' => 1, 'status' => 'queued',
            'operational_effect' => 'NO_OPERATIONAL_ACTION', 'requested_at' => now(),
        ]));
        $context->run($ownerA->tenant_id, function () {
            try {
                DB::transaction(fn () => app(IntelligenceCapacity::class)->assertAvailable());
                $this->fail('Tenant capacity should reject another run.');
            } catch (HttpException $exception) {
                $this->assertSame(429, $exception->getStatusCode());
            }
        });
        $context->run($ownerB->tenant_id, fn () => DB::transaction(fn () => app(IntelligenceCapacity::class)->assertAvailable()));
        config(['security.intelligence.global_pending' => 1]);
        $context->run($ownerB->tenant_id, function () {
            try {
                DB::transaction(fn () => app(IntelligenceCapacity::class)->assertAvailable());
                $this->fail('The global budget must also apply across tenants.');
            } catch (HttpException $exception) {
                $this->assertSame(429, $exception->getStatusCode());
            }
        });
    }
}
