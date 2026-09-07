<?php

namespace Tests\Feature;

use App\Actions\Operations\RefreshPlatformOperationalIncidents;
use App\Models\PlatformOperationalIncident;
use App\Models\PlatformOperationalIncidentEvent;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformOperationsMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
        config([
            'operations.monitoring.queue_backlog_max' => 100,
            'operations.monitoring.queue_stale_minutes' => 10,
            'operations.monitoring.failed_jobs_max' => 0,
            'operations.monitoring.cmi_failures_max' => 3,
            'operations.monitoring.disk_free_min_percent' => 1,
        ]);
    }

    public function test_monitor_opens_and_resolves_a_failed_queue_incident_with_append_only_events(): void
    {
        $this->artisan('operations:scheduler-heartbeat')->assertSuccessful();
        DB::table('failed_jobs')->insert([
            'uuid' => '00000000-0000-4000-8000-000000000001',
            'connection' => 'database',
            'queue' => 'intelligence',
            'payload' => '{}',
            'exception' => 'Sanitized test failure',
            'failed_at' => now(),
        ]);

        $opened = app(RefreshPlatformOperationalIncidents::class)->handle();
        $incident = PlatformOperationalIncident::query()->where('code', 'queue.failed')->sole();

        $this->assertSame(1, $opened['open']);
        $this->assertSame('open', $incident->status);
        $this->assertSame('critical', $incident->severity);
        $this->assertSame('opened', $incident->events()->sole()->event_type);

        DB::table('failed_jobs')->delete();
        $resolved = app(RefreshPlatformOperationalIncidents::class)->handle();

        $this->assertSame(1, $resolved['resolved']);
        $this->assertSame('resolved', $incident->refresh()->status);
        $this->assertNotNull($incident->resolved_at);
        $this->assertSame(['opened', 'resolved'], $incident->events()->orderBy('id')->pluck('event_type')->all());

        $this->expectException(QueryException::class);
        PlatformOperationalIncidentEvent::query()->where('event_type', 'opened')->update(['summary' => 'Réécriture interdite']);
    }

    public function test_missing_scheduler_heartbeat_is_critical_then_recovers(): void
    {
        app(RefreshPlatformOperationalIncidents::class)->handle();
        $incident = PlatformOperationalIncident::query()->where('code', 'scheduler.stale')->sole();

        $this->assertSame('open', $incident->status);
        $this->assertSame('critical', $incident->severity);

        $this->artisan('operations:scheduler-heartbeat')->assertSuccessful();
        app(RefreshPlatformOperationalIncidents::class)->handle();

        $this->assertSame('resolved', $incident->refresh()->status);
    }

    public function test_platform_monitor_is_scheduled_and_only_active_platform_admins_can_open_or_refresh_it(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'operations:monitor-platform'));
        $this->assertNotNull($event);
        $this->assertSame('Africa/Casablanca', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);

        $platform = User::factory()->create([
            'tenant_id' => null,
            'agency_id' => null,
            'role_id' => null,
            'is_platform_admin' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $tenantUser = User::factory()->create([
            'is_platform_admin' => false,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->get(route('platform.operations.index'))->assertRedirect(route('login'));
        $this->actingAs($tenantUser)->get(route('platform.operations.index'))->assertForbidden();
        $this->actingAs($platform)->get(route('platform.operations.index'))
            ->assertOk()
            ->assertSee('Supervision de production')
            ->assertSee('La collecte est inactive ou trop ancienne.');
        $this->actingAs($platform)->post(route('platform.operations.refresh'))
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->get(route('platform.operations.index'))
            ->assertOk()
            ->assertDontSee('La collecte est inactive ou trop ancienne.');
    }

    public function test_command_returns_only_aggregate_non_sensitive_results(): void
    {
        $this->artisan('operations:scheduler-heartbeat')->assertSuccessful();
        $this->assertSame(0, Artisan::call('operations:monitor-platform', ['--json' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('"checked":7', $output);
        $this->assertStringNotContainsString('password', $output);
        $this->assertStringNotContainsString('APP_KEY', $output);
    }
}
