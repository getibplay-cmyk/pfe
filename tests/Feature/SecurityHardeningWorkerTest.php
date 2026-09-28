<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class SecurityHardeningWorkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_job_cannot_leave_its_tenant_or_actor_for_the_next_job(): void
    {
        $ownerA = $this->createTenantOwner();
        $ownerB = $this->createTenantOwner();
        $context = app(TenantContext::class);
        $agencyA = $context->run($ownerA->tenant_id, fn () => Agency::factory()->create());
        $agencyB = $context->run($ownerB->tenant_id, fn () => Agency::factory()->create());
        $context->setFromUser($ownerA);
        Auth::setUser($ownerA);
        $job = Mockery::mock(DatabaseJob::class);
        $job->shouldReceive('payload')->andReturn([]);
        Event::dispatch(new JobExceptionOccurred('database', $job, new \RuntimeException('Synthetic failure')));
        $this->assertFalse($context->hasTenant());
        $this->assertFalse(Auth::check());
        Event::dispatch(new JobProcessing('database', $job));
        $context->run($ownerB->tenant_id, function () use ($agencyA, $agencyB) {
            $this->assertFalse(Agency::query()->whereKey($agencyA->id)->exists());
            $this->assertTrue(Agency::query()->whereKey($agencyB->id)->exists());
        });
        $this->assertFalse($context->hasTenant());
    }

    public function test_a_synchronous_job_does_not_erase_the_active_http_context(): void
    {
        $owner = $this->createTenantOwner();
        $context = app(TenantContext::class);
        $context->setFromUser($owner);
        Auth::setUser($owner);
        $job = Mockery::mock(SyncJob::class);
        $job->shouldReceive('payload')->andReturn([]);
        Event::dispatch(new JobProcessing('sync', $job));
        $this->assertSame($owner->tenant_id, $context->tenantId());
        $this->assertSame($owner->id, Auth::id());
        $context->clear();
    }
}
