<?php

namespace Tests\Feature;

use App\Enums\IntelligenceCapability;
use App\Enums\TenantStatus;
use App\Models\Agency;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Intelligence\IntelligenceCapabilityCatalog;
use App\Support\Platform\BuildPlatformStatistics;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class PlatformStatisticsAndTenantOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);

        $catalog = Mockery::mock(IntelligenceCapabilityCatalog::class)->makePartial();
        $catalog->shouldReceive('runtimeReady')->zeroOrMoreTimes()->andReturnFalse();
        $this->app->instance(IntelligenceCapabilityCatalog::class, $catalog);
    }

    public function test_platform_statistics_are_aggregated_exactly_and_reject_unknown_filters(): void
    {
        $this->travelTo(
            CarbonImmutable::create(2026, 8, 15, 12, 0, 0, config('app.timezone')),
            fn () => $this->assertPlatformStatisticsAreAggregatedExactlyAndRejectUnknownFilters(),
        );
    }

    public function test_saas_payment_statistics_include_the_last_local_hour_of_the_period(): void
    {
        $this->travelTo(
            CarbonImmutable::create(2026, 8, 30, 23, 30, 0, config('app.timezone')),
            function (): void {
                $platform = User::factory()->create([
                    'tenant_id' => null,
                    'agency_id' => null,
                    'role_id' => null,
                    'is_platform_admin' => true,
                    'is_active' => true,
                ]);
                $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
                $planId = DB::table('saas_plans')->insertGetId([
                    'code' => 'timezone-regression',
                    'name' => 'Plan fuseau',
                    'billing_interval' => 'monthly',
                    'price_amount' => '75.00',
                    'currency' => 'MAD',
                    'features' => '[]',
                    'is_active' => true,
                    'created_by' => $platform->id,
                    'updated_by' => $platform->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $subscriptionId = DB::table('saas_subscriptions')->insertGetId([
                    'tenant_id' => $tenant->id,
                    'saas_plan_id' => $planId,
                    'status' => 'active',
                    'billing_interval' => 'monthly',
                    'price_amount' => '75.00',
                    'currency' => 'MAD',
                    'starts_at' => now()->subDay(),
                    'next_renewal_at' => now()->addMonth(),
                    'created_by' => $platform->id,
                    'updated_by' => $platform->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('saas_payments')->insert([
                    'tenant_id' => $tenant->id,
                    'saas_subscription_id' => $subscriptionId,
                    'entry_type' => 'payment',
                    'payment_method' => 'bank_transfer',
                    'amount' => '75.00',
                    'currency' => 'MAD',
                    'idempotency_key' => 'timezone-regression-payment',
                    'occurred_at' => now(),
                    'created_by' => $platform->id,
                    'created_at' => now(),
                ]);

                $startsAt = CarbonImmutable::create(2026, 8, 30, 0, 0, 0, config('app.timezone'));
                $statistics = app(BuildPlatformStatistics::class)->handle($startsAt, $startsAt->addDay());

                $this->assertSame(1, $statistics['totals']['recorded_saas_payments']);
                $this->assertSame([
                    ['currency' => 'MAD', 'amount' => '75.00'],
                ], $statistics['payments']['currencies']);
            },
        );
    }

    private function assertPlatformStatisticsAreAggregatedExactlyAndRejectUnknownFilters(): void
    {
        $platform = User::factory()->create([
            'tenant_id' => null,
            'agency_id' => null,
            'role_id' => null,
            'is_platform_admin' => true,
            'is_active' => true,
        ]);
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
        $planId = DB::table('saas_plans')->insertGetId([
            'code' => 'test-monthly',
            'name' => 'Plan de test',
            'billing_interval' => 'monthly',
            'price_amount' => '100.00',
            'currency' => 'MAD',
            'features' => '[]',
            'is_active' => true,
            'created_by' => $platform->id,
            'updated_by' => $platform->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $subscriptionId = DB::table('saas_subscriptions')->insertGetId([
            'tenant_id' => $tenant->id,
            'saas_plan_id' => $planId,
            'status' => 'active',
            'billing_interval' => 'monthly',
            'price_amount' => '100.00',
            'currency' => 'MAD',
            'starts_at' => now()->subDay(),
            'next_renewal_at' => now()->addMonth(),
            'created_by' => $platform->id,
            'updated_by' => $platform->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('saas_payments')->insert([
            'tenant_id' => $tenant->id,
            'saas_subscription_id' => $subscriptionId,
            'entry_type' => 'payment',
            'payment_method' => 'bank_transfer',
            'amount' => '100.00',
            'currency' => 'MAD',
            'idempotency_key' => 'p-mad',
            'occurred_at' => now(),
            'created_by' => $platform->id,
            'created_at' => now(),
        ]);

        $euroTenant = Tenant::factory()->withIntelligenceDisabled()->create(['status' => TenantStatus::Active]);
        $euroPlanId = DB::table('saas_plans')->insertGetId([
            'code' => 'test-annual-eur',
            'name' => 'Plan annuel EUR',
            'billing_interval' => 'annual',
            'price_amount' => '240.00',
            'currency' => 'EUR',
            'features' => '[]',
            'is_active' => true,
            'created_by' => $platform->id,
            'updated_by' => $platform->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $euroSubscriptionId = DB::table('saas_subscriptions')->insertGetId([
            'tenant_id' => $euroTenant->id,
            'saas_plan_id' => $euroPlanId,
            'status' => 'active',
            'billing_interval' => 'annual',
            'price_amount' => '240.00',
            'currency' => 'EUR',
            'starts_at' => now()->subDay(),
            'next_renewal_at' => now()->addYear(),
            'created_by' => $platform->id,
            'updated_by' => $platform->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('saas_payments')->insert([
            'tenant_id' => $euroTenant->id,
            'saas_subscription_id' => $euroSubscriptionId,
            'entry_type' => 'payment',
            'payment_method' => 'bank_transfer',
            'amount' => '20.00',
            'currency' => 'EUR',
            'idempotency_key' => 'p-eur',
            'occurred_at' => now(),
            'created_by' => $platform->id,
            'created_at' => now(),
        ]);
        DB::table('tenant_intelligence_accesses')
            ->where('tenant_id', $tenant->id)
            ->where('capability', IntelligenceCapability::DemandForecast->value)
            ->update([
                'enabled' => true,
                'updated_by' => $platform->id,
                'changed_at' => now(),
                'updated_at' => now(),
            ]);

        $response = $this->actingAs($platform)->get(route('platform.statistics.index', [
            'date_from' => today()->subDays(7)->toDateString(),
            'date_to' => today()->toDateString(),
        ]));

        $response->assertOk()->assertViewIs('platform.statistics');
        $statistics = $response->viewData('statistics');
        $this->assertSame(2, $statistics['totals']['tenants']);
        $this->assertSame(2, $statistics['totals']['active_tenants']);
        $this->assertSame(2, $statistics['totals']['recorded_saas_payments']);
        $this->assertSame([
            ['currency' => 'EUR', 'amount' => '20.00'],
            ['currency' => 'MAD', 'amount' => '100.00'],
        ], $statistics['payments']['currencies']);
        $this->assertCount(6, $statistics['activations']);
        $this->assertSame(1, collect($statistics['activations'])->firstWhere('capability', 'demand_forecast')['tenant_count']);
        $response->assertDontSee('idempotency_key')->assertDontSee('runtime_sha256');

        $this->actingAs($platform)
            ->get(route('platform.statistics.index', ['unexpected' => 'value']))
            ->assertSessionHasErrors('filters');
        $this->actingAs($platform)
            ->get(route('platform.statistics.index', [
                'date_from' => today()->subDays(367)->toDateString(),
                'date_to' => today()->toDateString(),
            ]))
            ->assertSessionHasErrors('date_to');
    }

    public function test_tenant_owner_alone_can_read_its_saas_account_without_foreign_or_technical_data(): void
    {
        $ownerRole = Role::query()->where('slug', 'tenant-owner')->whereNull('tenant_id')->firstOrFail();
        $managerRole = Role::query()->where('slug', 'agency-manager')->whereNull('tenant_id')->firstOrFail();
        $tenant = Tenant::factory()->create();
        $foreign = Tenant::factory()->create();
        $agency = app(TenantContext::class)->run(
            $tenant,
            fn (): Agency => Agency::factory()->create(),
        );
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => null, 'role_id' => $ownerRole->id]);
        $manager = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => $agency->id, 'role_id' => $managerRole->id]);

        $this->actingAs($owner)->get(route('tenant-saas-account.show'))
            ->assertOk()
            ->assertViewIs('tenant.account-saas')
            ->assertSee($tenant->name)
            ->assertDontSee($foreign->name)
            ->assertDontSee('idempotency_key')
            ->assertDontSee('runtime_sha256');
        $this->actingAs($manager)->get(route('tenant-saas-account.show'))->assertForbidden();
        $this->app['auth']->logout();
        $this->get(route('tenant-saas-account.show'))->assertRedirect(route('login'));
    }
}
