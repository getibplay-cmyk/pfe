<?php

namespace Tests\Feature;

use App\Actions\PlatformBilling\AssignSaasSubscription;
use App\Actions\PlatformBilling\CreateSaasPlan;
use App\Actions\PlatformBilling\TransitionSaasSubscription;
use App\Actions\PlatformBilling\UpdateSaasPlan;
use App\Actions\Tenancy\CreateTenantUser;
use App\Enums\IntelligenceCapability;
use App\Enums\PlatformBilling\TenantSubscriptionStatus;
use App\Models\Agency;
use App\Models\PlatformBilling\SaasPlan;
use App\Models\PlatformBilling\SaasSubscription;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Intelligence\TenantIntelligenceAccess;
use App\Support\PlatformBilling\TenantPlanAccess;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SaasPlanEntitlementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_subscription_keeps_an_immutable_entitlement_snapshot_when_the_plan_changes(): void
    {
        $platform = $this->platformAdmin();
        $tenant = Tenant::factory()->create();
        $plan = $this->plan($platform, 'starter-snapshot', [
            'max_agencies' => 1,
            'max_users' => 3,
            'max_vehicles' => 10,
            'monthly_intelligence_runs' => 20,
            'intelligence_capabilities' => [IntelligenceCapability::DemandForecast->value],
        ]);
        $subscription = $this->subscription($platform, $tenant, $plan);

        app(UpdateSaasPlan::class)->handle($plan, [
            'name' => 'Starter renforcé',
            'description' => 'Nouveaux quotas réservés aux futurs abonnements.',
            'price_amount' => '599.00',
            'currency' => 'MAD',
            'features' => ['Gestion de flotte'],
            'is_active' => true,
            'entitlements_configured' => true,
            'max_agencies' => 2,
            'max_users' => 8,
            'max_vehicles' => 30,
            'monthly_intelligence_runs' => 100,
            'intelligence_capabilities' => [
                IntelligenceCapability::DemandForecast->value,
                IntelligenceCapability::FleetReallocation->value,
            ],
        ], $platform->getKey());

        $this->assertSame(30, $plan->refresh()->entitlements['max_vehicles']);
        $this->assertSame(10, $subscription->refresh()->entitlements['max_vehicles']);
        $this->assertSame(
            [IntelligenceCapability::DemandForecast->value],
            $subscription->entitlements['intelligence_capabilities'],
        );

        $this->expectException(QueryException::class);
        DB::table('saas_subscriptions')->where('id', $subscription->getKey())->update([
            'entitlements' => json_encode([
                ...$subscription->entitlements,
                'max_vehicles' => 999,
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    public function test_legacy_accounts_remain_unlimited_but_subscribed_accounts_are_enforced(): void
    {
        $legacy = Tenant::factory()->create();
        $planAccess = app(TenantPlanAccess::class);

        $legacyQuota = $planAccess->quota('vehicles', $legacy->getKey());
        $this->assertTrue($legacyQuota['legacy']);
        $this->assertNull($legacyQuota['limit']);
        $this->assertTrue($legacyQuota['allowed']);

        $platform = $this->platformAdmin();
        $tenant = Tenant::factory()->create();
        $plan = $this->plan($platform, 'strict-limits', [
            'max_agencies' => 1,
            'max_users' => 1,
            'max_vehicles' => 0,
            'monthly_intelligence_runs' => 10,
            'intelligence_capabilities' => [IntelligenceCapability::DemandForecast->value],
        ]);
        $subscription = $this->subscription($platform, $tenant, $plan);
        $context = app(TenantContext::class);
        $agency = $context->run($tenant, fn (): Agency => Agency::factory()->create());
        $owner = User::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'agency_id' => null,
            'role_id' => Role::query()->whereNull('tenant_id')->where('slug', 'tenant-owner')->value('id'),
            'is_platform_admin' => false,
            'is_active' => true,
        ]);

        $this->assertFalse($planAccess->quota('agencies', $tenant->getKey())['allowed']);
        $this->assertFalse($planAccess->quota('users', $tenant->getKey())['allowed']);
        $this->assertFalse($planAccess->quota('vehicles', $tenant->getKey())['allowed']);
        $this->assertTrue(app(TenantIntelligenceAccess::class)->authorized(
            IntelligenceCapability::DemandForecast,
            $tenant->getKey(),
        ));
        $this->assertFalse(app(TenantIntelligenceAccess::class)->authorized(
            IntelligenceCapability::VehicleColor,
            $tenant->getKey(),
        ));
        try {
            DB::transaction(fn () => $planAccess->ensureCanUseIntelligence(
                IntelligenceCapability::VehicleColor,
                $tenant->getKey(),
            ));
            $this->fail('Une assistance absente du plan doit être refusée dans la transaction métier.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('plan', $exception->errors());
        }

        $this->actingAs($owner)->post(route('agencies.store'), [
            'code' => 'RABAT-2',
            'name' => 'Agence supplémentaire',
            'email' => null,
            'phone' => null,
            'address' => null,
        ])->assertRedirect()->assertSessionHasErrors('plan');
        $this->assertDatabaseMissing('agencies', [
            'tenant_id' => $tenant->getKey(),
            'code' => 'RABAT-2',
        ]);
        $this->assertDatabaseHas('agencies', ['id' => $agency->getKey()]);

        $inactiveUser = $context->run($tenant, fn (): User => app(CreateTenantUser::class)->handle([
            'name' => 'Compte inactif',
            'email' => 'inactive@example.test',
            'role_id' => Role::query()->whereNull('tenant_id')->where('slug', 'rental-agent')->value('id'),
            'agency_id' => $agency->getKey(),
            'is_active' => false,
        ], $owner)['user']);
        $this->assertFalse($inactiveUser->is_active);
        $this->assertSame(1, $planAccess->quota('users', $tenant->getKey())['used']);

        app(TransitionSaasSubscription::class)->handle(
            $subscription,
            TenantSubscriptionStatus::Suspended,
            $platform->getKey(),
        );
        $this->assertFalse($planAccess->quota('intelligence_runs', $tenant->getKey())['allowed']);
    }

    public function test_zero_monthly_intelligence_quota_blocks_even_an_included_capability(): void
    {
        $platform = $this->platformAdmin();
        $tenant = Tenant::factory()->create();
        $plan = $this->plan($platform, 'no-ai-runs', [
            'max_agencies' => null,
            'max_users' => null,
            'max_vehicles' => null,
            'monthly_intelligence_runs' => 0,
            'intelligence_capabilities' => [IntelligenceCapability::DemandForecast->value],
        ]);
        $this->subscription($platform, $tenant, $plan);

        $this->assertFalse(app(TenantIntelligenceAccess::class)->authorized(
            IntelligenceCapability::DemandForecast,
            $tenant->getKey(),
        ));

        try {
            DB::transaction(fn () => app(TenantPlanAccess::class)->ensureCanUseIntelligence(
                IntelligenceCapability::DemandForecast,
                $tenant->getKey(),
            ));
            $this->fail('Le quota nul doit empêcher une nouvelle analyse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('plan', $exception->errors());
        }
    }

    /** @param array<string, mixed> $entitlements */
    private function plan(User $platform, string $code, array $entitlements): SaasPlan
    {
        return app(CreateSaasPlan::class)->handle([
            'code' => $code,
            'name' => ucfirst(str_replace('-', ' ', $code)),
            'description' => 'Plan de test des droits SaaS.',
            'billing_interval' => 'monthly',
            'price_amount' => '499.00',
            'currency' => 'MAD',
            'features' => ['Gestion de flotte'],
            'is_active' => true,
            'entitlements_configured' => true,
            ...$entitlements,
        ], $platform->getKey());
    }

    private function subscription(User $platform, Tenant $tenant, SaasPlan $plan): SaasSubscription
    {
        return app(AssignSaasSubscription::class)->handle($tenant, $plan, [
            'status' => 'active',
            'starts_at' => now()->toIso8601String(),
            'ends_at' => now()->addYear()->toIso8601String(),
            'trial_ends_at' => null,
            'next_renewal_at' => now()->addMonth()->toIso8601String(),
            'admin_note' => 'Test des droits SaaS.',
        ], $platform->getKey());
    }

    private function platformAdmin(): User
    {
        return User::factory()->create([
            'tenant_id' => null,
            'agency_id' => null,
            'role_id' => null,
            'is_platform_admin' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }
}
