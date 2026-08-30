<?php

namespace Tests\Feature;

use App\Actions\PlatformBilling\AssignSaasSubscription;
use App\Actions\PlatformBilling\CreateSaasPlan;
use App\Actions\PlatformBilling\RecordSaasPayment;
use App\Enums\IntelligenceCapability;
use App\Models\Agency;
use App\Models\PlatformBilling\SaasPayment;
use App\Models\PlatformBilling\SaasPlan;
use App\Models\PlatformBilling\SaasSubscription;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantIntelligenceAccess;
use App\Models\User;
use App\Support\Intelligence\IntelligenceCapabilityCatalog;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

class PlatformAdministrationHttpTest extends TestCase
{
    use RefreshDatabase;

    private int $fixtureSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('rentfleet_test', $this->assertUsesAuthorizedPostgreSqlTestDatabase());
        $this->assertSame('rentfleet_test', DB::scalar('select current_database()'));
        $this->seed(RolesPermissionsSeeder::class);

        $catalog = Mockery::mock(IntelligenceCapabilityCatalog::class)->makePartial();
        $catalog->shouldReceive('runtimeReady')->zeroOrMoreTimes()->andReturnFalse();
        $this->app->instance(IntelligenceCapabilityCatalog::class, $catalog);
    }

    public function test_all_platform_administration_surfaces_redirect_anonymous_visitors_without_side_effects(): void
    {
        $platform = $this->platformAdmin();
        $tenant = Tenant::factory()->create();
        $plan = $this->createPlan($platform);
        $subscription = $this->createSubscription($platform, $tenant, $plan);
        $payment = $this->createPayment($platform, $subscription);
        $before = $this->platformCounts();

        foreach ([
            route('platform.plans.index'),
            route('platform.subscriptions.index'),
            route('platform.tenants.subscriptions.create', $tenant),
            route('platform.saas-payments.index'),
            route('platform.tenants.saas-payments.create', $tenant),
            route('platform.intelligence.index'),
            route('tenant-saas-account.show'),
        ] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }

        foreach ($this->platformMutationResponses($tenant, $plan, $subscription, $payment) as $response) {
            $response->assertRedirect(route('login'));
        }

        $this->assertSame($before, $this->platformCounts());
    }

    public function test_tenant_owner_and_tenant_user_cannot_use_any_platform_mutation(): void
    {
        $fixtureAdmin = $this->platformAdmin();
        $tenant = Tenant::factory()->create();
        $plan = $this->createPlan($fixtureAdmin);
        $subscription = $this->createSubscription($fixtureAdmin, $tenant, $plan);
        $payment = $this->createPayment($fixtureAdmin, $subscription);
        $owner = $this->tenantActor($tenant, 'tenant-owner');
        $manager = $this->tenantActor($tenant, 'agency-manager');
        $before = $this->platformCounts();

        foreach ([$owner, $manager] as $actor) {
            $this->actingAs($actor)->get(route('platform.plans.index'))->assertForbidden();
            $this->actingAs($actor)->get(route('platform.intelligence.index'))->assertForbidden();

            foreach ($this->platformMutationResponses($tenant, $plan, $subscription, $payment) as $response) {
                $response->assertForbidden();
            }
        }

        $this->assertSame($before, $this->platformCounts());
    }

    public function test_inactive_platform_admin_is_refused_on_reads_and_mutations(): void
    {
        $fixtureAdmin = $this->platformAdmin();
        $tenant = Tenant::factory()->create();
        $plan = $this->createPlan($fixtureAdmin);
        $subscription = $this->createSubscription($fixtureAdmin, $tenant, $plan);
        $payment = $this->createPayment($fixtureAdmin, $subscription);
        $inactive = $this->platformAdmin(false);
        $before = $this->platformCounts();

        $this->actingAs($inactive)->get(route('platform.plans.index'))->assertForbidden();
        $this->actingAs($inactive)->get(route('platform.subscriptions.index'))->assertForbidden();
        $this->actingAs($inactive)->get(route('platform.saas-payments.index'))->assertForbidden();
        $this->actingAs($inactive)->get(route('platform.intelligence.index'))->assertForbidden();

        foreach ($this->platformMutationResponses($tenant, $plan, $subscription, $payment) as $response) {
            $response->assertForbidden();
        }

        $this->assertSame($before, $this->platformCounts());
    }

    public function test_active_platform_admin_can_manage_plans_subscriptions_manual_payments_and_intelligence(): void
    {
        $platform = $this->platformAdmin();
        $tenant = Tenant::factory()->create();
        $rentalFinanceBefore = $this->rentalFinanceCounts();

        $this->actingAs($platform)->get(route('platform.plans.index'))->assertOk();
        $this->actingAs($platform)->get(route('platform.subscriptions.index'))->assertOk();
        $this->actingAs($platform)->get(route('platform.saas-payments.index'))->assertOk();
        $this->actingAs($platform)->get(route('platform.intelligence.index'))->assertOk();

        $planPayload = $this->validPlanPayload('http-administration');
        $this->actingAs($platform)
            ->post(route('platform.plans.store'), $planPayload)
            ->assertRedirect(route('platform.plans.index'))
            ->assertSessionHasNoErrors();
        $plan = SaasPlan::query()->where('code', 'http-administration')->firstOrFail();
        $this->assertSame($platform->getKey(), $plan->created_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.saas_plan.created']);

        $this->actingAs($platform)
            ->patch(route('platform.plans.update', $plan), $this->validPlanUpdatePayload())
            ->assertRedirect(route('platform.plans.index'))
            ->assertSessionHasNoErrors();
        $this->assertSame('799.90', $plan->refresh()->price_amount);

        $this->actingAs($platform)
            ->get(route('platform.tenants.subscriptions.create', $tenant))
            ->assertOk();
        $this->actingAs($platform)
            ->post(
                route('platform.tenants.subscriptions.store', $tenant),
                $this->validSubscriptionPayload($plan),
            )
            ->assertRedirect(route('platform.tenants.show', $tenant))
            ->assertSessionHasNoErrors();
        $subscription = SaasSubscription::query()->where('tenant_id', $tenant->getKey())->firstOrFail();
        $this->assertSame('799.90', $subscription->price_amount);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->getKey(),
            'action' => 'platform.subscription.created',
        ]);

        $this->actingAs($platform)
            ->from(route('platform.subscriptions.index'))
            ->patch(route('platform.subscriptions.transition', $subscription), ['status' => 'past_due'])
            ->assertRedirect(route('platform.subscriptions.index'))
            ->assertSessionHasNoErrors();
        $this->assertSame('past_due', $subscription->refresh()->status->value);

        $this->actingAs($platform)
            ->get(route('platform.tenants.saas-payments.create', $tenant))
            ->assertOk();
        $this->actingAs($platform)
            ->post(
                route('platform.tenants.saas-payments.store', [$tenant, $subscription]),
                $this->validPaymentPayload('http-payment-active'),
            )
            ->assertRedirect(route('platform.saas-payments.index'))
            ->assertSessionHasNoErrors();
        $payment = SaasPayment::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('entry_type', 'payment')
            ->firstOrFail();
        $this->assertSame('799.90', $subscription->price_amount);
        $this->assertSame('150.00', $payment->amount);

        $this->actingAs($platform)
            ->from(route('platform.saas-payments.index'))
            ->post(
                route('platform.saas-payments.reverse', $payment),
                $this->validReversalPayload('http-reversal-active'),
            )
            ->assertRedirect(route('platform.saas-payments.index'))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('saas_payments', [
            'tenant_id' => $tenant->getKey(),
            'entry_type' => 'reversal',
            'reversal_of_id' => $payment->getKey(),
            'amount' => '150.00',
        ]);
        $this->assertSame('payment', $payment->refresh()->entry_type->value);

        $this->actingAs($platform)
            ->from(route('platform.intelligence.index'))
            ->patch(route('platform.intelligence.update', [
                $tenant,
                IntelligenceCapability::VehicleColor->value,
            ]), ['enabled' => false])
            ->assertRedirect(route('platform.intelligence.index'))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tenant_intelligence_accesses', [
            'tenant_id' => $tenant->getKey(),
            'capability' => IntelligenceCapability::VehicleColor->value,
            'enabled' => false,
            'updated_by' => $platform->getKey(),
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.saas_plan.updated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.subscription.status_changed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.saas_payment.recorded']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.saas_payment.reversed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.intelligence_access.updated']);
        $this->assertSame($rentalFinanceBefore, $this->rentalFinanceCounts());
    }

    public function test_unknown_forged_and_non_initial_fields_are_rejected_before_any_effect(): void
    {
        $platform = $this->platformAdmin();
        $emptyTenant = Tenant::factory()->create();
        $billingTenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $plan = $this->createPlan($platform);
        $subscription = $this->createSubscription($platform, $billingTenant, $plan);
        $before = $this->platformCounts();

        $this->actingAs($platform)
            ->postJson(route('platform.plans.store'), [
                ...$this->validPlanPayload('forged-http-plan'),
                'tenant_id' => $foreignTenant->getKey(),
                'unexpected' => 'value',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant_id', 'unexpected']);

        foreach (['past_due', 'suspended', 'cancelled', 'expired'] as $forgedStatus) {
            $this->actingAs($platform)
                ->postJson(route('platform.tenants.subscriptions.store', $emptyTenant), [
                    ...$this->validSubscriptionPayload($plan),
                    'status' => $forgedStatus,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('status');
        }

        $this->actingAs($platform)
            ->postJson(route('platform.tenants.subscriptions.store', $emptyTenant), [
                ...$this->validSubscriptionPayload($plan),
                'tenant_id' => $foreignTenant->getKey(),
                'price_amount' => '0.01',
                'currency' => 'EUR',
                'unexpected' => 'value',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant_id', 'price_amount', 'currency', 'unexpected']);

        $this->actingAs($platform)
            ->postJson(
                route('platform.tenants.saas-payments.store', [$billingTenant, $subscription]),
                [
                    ...$this->validPaymentPayload('forged-http-payment'),
                    'tenant_id' => $foreignTenant->getKey(),
                    'saas_subscription_id' => -1,
                    'currency' => 'EUR',
                    'unexpected' => 'value',
                ],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'tenant_id',
                'saas_subscription_id',
                'currency',
                'unexpected',
            ]);

        $this->actingAs($platform)
            ->patchJson(route('platform.intelligence.update', [
                $emptyTenant,
                IntelligenceCapability::DemandForecast->value,
            ]), [
                'enabled' => false,
                'tenant_id' => $foreignTenant->getKey(),
                'capability' => IntelligenceCapability::VehicleDamage->value,
                'updated_by' => $foreignTenant->getKey(),
                'unexpected' => 'value',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'tenant_id',
                'capability',
                'updated_by',
                'unexpected',
            ]);

        $this->assertSame($before, $this->platformCounts());
    }

    public function test_manual_payment_rejects_a_subscription_from_another_tenant_with_404(): void
    {
        $platform = $this->platformAdmin();
        $requestedTenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $plan = $this->createPlan($platform);
        $foreignSubscription = $this->createSubscription($platform, $foreignTenant, $plan);
        $paymentsBefore = SaasPayment::query()->count();
        $auditsBefore = DB::table('audit_logs')->count();

        $this->actingAs($platform)
            ->postJson(
                route('platform.tenants.saas-payments.store', [$requestedTenant, $foreignSubscription]),
                $this->validPaymentPayload('tenant-mismatch-payment'),
            )
            ->assertNotFound();

        $this->assertSame($paymentsBefore, SaasPayment::query()->count());
        $this->assertSame($auditsBefore, DB::table('audit_logs')->count());
    }

    public function test_tenant_owner_reads_only_its_own_saas_account_even_with_a_forged_query_scope(): void
    {
        $platform = $this->platformAdmin();
        $tenant = Tenant::factory()->create(['name' => 'Entreprise Propriétaire']);
        $foreignTenant = Tenant::factory()->create(['name' => 'Entreprise Étrangère Invisible']);
        $ownerPlan = $this->createPlan($platform, 'Plan Propriétaire Visible');
        $foreignPlan = $this->createPlan($platform, 'Plan Étranger Invisible');
        $ownerSubscription = $this->createSubscription($platform, $tenant, $ownerPlan);
        $foreignSubscription = $this->createSubscription($platform, $foreignTenant, $foreignPlan);
        $this->createPayment($platform, $ownerSubscription);
        $this->createPayment($platform, $foreignSubscription);
        $owner = $this->tenantActor($tenant, 'tenant-owner');

        $response = $this->actingAs($owner)->get(route('tenant-saas-account.show', [
            'tenant_id' => $foreignTenant->getKey(),
        ]));

        $response->assertOk()
            ->assertViewIs('tenant.account-saas')
            ->assertSee($tenant->name)
            ->assertSee($ownerPlan->name)
            ->assertDontSee($foreignTenant->name)
            ->assertDontSee($foreignPlan->name)
            ->assertDontSee('idempotency_key');
        $this->assertSame($tenant->getKey(), $response->viewData('tenant')->getKey());
        $this->assertTrue($response->viewData('subscriptions')->every(
            fn (SaasSubscription $item): bool => $item->tenant_id === $tenant->getKey(),
        ));
        $this->assertTrue($response->viewData('payments')->every(
            fn (SaasPayment $item): bool => $item->tenant_id === $tenant->getKey(),
        ));

        $before = $this->platformCounts();
        $this->actingAs($owner)
            ->post(route('platform.plans.store'), $this->validPlanPayload('owner-forbidden-plan'))
            ->assertForbidden();
        $this->assertSame($before, $this->platformCounts());
    }

    public function test_intelligence_activation_is_tenant_scoped_and_audited_over_http(): void
    {
        $platform = $this->platformAdmin();
        $target = Tenant::factory()->create();
        $foreign = Tenant::factory()->create();
        $capability = IntelligenceCapability::RentalUsageAnomaly;
        $auditsBefore = DB::table('audit_logs')->count();

        $this->actingAs($platform)
            ->from(route('platform.intelligence.index'))
            ->patch(route('platform.intelligence.update', [$target, $capability->value]), [
                'enabled' => false,
            ])
            ->assertRedirect(route('platform.intelligence.index'))
            ->assertSessionHasNoErrors();

        $targetAccess = TenantIntelligenceAccess::query()
            ->where('tenant_id', $target->getKey())
            ->where('capability', $capability->value)
            ->firstOrFail();
        $foreignAccess = TenantIntelligenceAccess::query()
            ->where('tenant_id', $foreign->getKey())
            ->where('capability', $capability->value)
            ->firstOrFail();
        $this->assertFalse($targetAccess->enabled);
        $this->assertSame($platform->getKey(), $targetAccess->updated_by);
        $this->assertTrue($foreignAccess->enabled);
        $this->assertSame($auditsBefore + 1, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $target->getKey(),
            'user_id' => $platform->getKey(),
            'action' => 'platform.intelligence_access.updated',
        ]);

        $this->actingAs($platform)
            ->patchJson(route('platform.intelligence.update', [$target, 'not-a-capability']), [
                'enabled' => true,
            ])
            ->assertNotFound();
        $this->assertSame($auditsBefore + 1, DB::table('audit_logs')->count());
        $this->assertFalse($targetAccess->fresh()->enabled);
        $this->assertTrue($foreignAccess->fresh()->enabled);
    }

    public function test_intelligence_access_scope_is_immutable_at_the_postgresql_boundary(): void
    {
        $tenant = Tenant::factory()->create();
        $foreign = Tenant::factory()->create();
        $access = TenantIntelligenceAccess::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('capability', IntelligenceCapability::VehicleDamage->value)
            ->firstOrFail();

        $this->expectException(QueryException::class);
        DB::table('tenant_intelligence_accesses')
            ->where('id', $access->getKey())
            ->update(['tenant_id' => $foreign->getKey()]);
    }

    public function test_all_platform_mutations_keep_the_web_csrf_boundary_and_shared_rate_limit(): void
    {
        foreach ([
            'platform.tenants.store',
            'platform.tenants.update',
            'platform.tenants.suspend',
            'platform.tenants.reactivate',
            'platform.plans.store',
            'platform.plans.update',
            'platform.tenants.subscriptions.store',
            'platform.subscriptions.transition',
            'platform.tenants.saas-payments.store',
            'platform.saas-payments.reverse',
            'platform.intelligence.update',
        ] as $routeName) {
            $route = app('router')->getRoutes()->getByName($routeName);

            $this->assertNotNull($route);
            $middleware = Collection::make($route->gatherMiddleware());
            $this->assertTrue($middleware->contains('web'), $routeName.' doit conserver la protection CSRF du groupe web.');
            $this->assertTrue($middleware->contains('auth'), $routeName.' doit exiger une session authentifiée.');
            $this->assertTrue($middleware->contains('platform'), $routeName.' doit exiger un administrateur plateforme.');
            $this->assertTrue($middleware->contains('throttle:30,1'), $routeName.' doit être limité à 30 mutations par minute.');
        }
    }

    private function platformAdmin(bool $active = true): User
    {
        return User::factory()->create([
            'tenant_id' => null,
            'agency_id' => null,
            'role_id' => null,
            'is_platform_admin' => true,
            'is_active' => $active,
        ]);
    }

    private function tenantActor(Tenant $tenant, string $roleSlug): User
    {
        $role = Role::query()
            ->whereNull('tenant_id')
            ->where('slug', $roleSlug)
            ->firstOrFail();
        $agency = $roleSlug === 'tenant-owner'
            ? null
            : app(TenantContext::class)->run(
                $tenant,
                fn (): Agency => Agency::factory()->create(),
            );

        return User::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'agency_id' => $agency?->getKey(),
            'role_id' => $role->getKey(),
            'is_platform_admin' => false,
            'is_active' => true,
        ]);
    }

    private function createPlan(User $platform, ?string $name = null): SaasPlan
    {
        $sequence = ++$this->fixtureSequence;

        return app(CreateSaasPlan::class)->handle([
            'code' => 'http-fixture-'.$sequence,
            'name' => $name ?? 'Plan HTTP '.$sequence,
            'description' => 'Plan administratif de test.',
            'billing_interval' => 'monthly',
            'price_amount' => '499.90',
            'currency' => 'MAD',
            'features' => ['Gestion de flotte', 'Rapports'],
            'is_active' => true,
        ], $platform->getKey());
    }

    private function createSubscription(
        User $platform,
        Tenant $tenant,
        SaasPlan $plan,
    ): SaasSubscription {
        return app(AssignSaasSubscription::class)->handle($tenant, $plan, [
            'status' => 'active',
            'starts_at' => now()->subDay()->toIso8601String(),
            'ends_at' => now()->addYear()->toIso8601String(),
            'trial_ends_at' => null,
            'next_renewal_at' => now()->addMonth()->toIso8601String(),
            'admin_note' => 'Abonnement administratif de test.',
        ], $platform->getKey());
    }

    private function createPayment(User $platform, SaasSubscription $subscription): SaasPayment
    {
        $sequence = ++$this->fixtureSequence;

        return app(RecordSaasPayment::class)->handle($subscription, [
            'payment_method' => 'bank_transfer',
            'amount' => '125.00',
            'reference' => 'REF-HTTP-'.$sequence,
            'idempotency_key' => 'http-fixture-payment-'.$sequence,
            'occurred_at' => now()->toIso8601String(),
            'note' => 'Paiement administratif de test.',
        ], $platform->getKey());
    }

    /** @return list<TestResponse> */
    private function platformMutationResponses(
        Tenant $tenant,
        SaasPlan $plan,
        SaasSubscription $subscription,
        SaasPayment $payment,
    ): array {
        return [
            $this->post(route('platform.plans.store'), $this->validPlanPayload('blocked-new-plan')),
            $this->patch(route('platform.plans.update', $plan), $this->validPlanUpdatePayload()),
            $this->post(
                route('platform.tenants.subscriptions.store', $tenant),
                $this->validSubscriptionPayload($plan),
            ),
            $this->patch(route('platform.subscriptions.transition', $subscription), ['status' => 'past_due']),
            $this->post(
                route('platform.tenants.saas-payments.store', [$tenant, $subscription]),
                $this->validPaymentPayload('blocked-payment'),
            ),
            $this->post(
                route('platform.saas-payments.reverse', $payment),
                $this->validReversalPayload('blocked-reversal'),
            ),
            $this->patch(route('platform.intelligence.update', [
                $tenant,
                IntelligenceCapability::VehicleColor->value,
            ]), ['enabled' => false]),
        ];
    }

    /** @return array<string, mixed> */
    private function validPlanPayload(string $code): array
    {
        return [
            'code' => $code,
            'name' => 'Plan administration HTTP',
            'description' => 'Plan SaaS manuel.',
            'billing_interval' => 'monthly',
            'price_amount' => '699.90',
            'currency' => 'MAD',
            'features' => ['Gestion de flotte', 'Assistances'],
            'is_active' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function validPlanUpdatePayload(): array
    {
        return [
            'name' => 'Plan administration HTTP actualisé',
            'description' => 'Plan SaaS manuel actualisé.',
            'price_amount' => '799.90',
            'currency' => 'MAD',
            'features' => ['Gestion de flotte', 'Assistances', 'Rapports'],
            'is_active' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function validSubscriptionPayload(SaasPlan $plan): array
    {
        return [
            'saas_plan_id' => $plan->getKey(),
            'status' => 'active',
            'starts_at' => now()->subHour()->toIso8601String(),
            'ends_at' => now()->addYear()->toIso8601String(),
            'trial_ends_at' => null,
            'next_renewal_at' => now()->addMonth()->toIso8601String(),
            'admin_note' => 'Affectation manuelle de test.',
        ];
    }

    /** @return array<string, mixed> */
    private function validPaymentPayload(string $idempotencyKey): array
    {
        return [
            'payment_method' => 'bank_transfer',
            'amount' => '150.00',
            'reference' => 'REF-HTTP-MANUAL',
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => now()->toIso8601String(),
            'note' => 'Saisie manuelle sans passerelle.',
        ];
    }

    /** @return array<string, mixed> */
    private function validReversalPayload(string $idempotencyKey): array
    {
        return [
            'reason' => 'Correction administrative documentée.',
            'reference' => 'REV-HTTP-MANUAL',
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => now()->toIso8601String(),
            'note' => 'Contrepassation append-only.',
        ];
    }

    /** @return array<string, int> */
    private function platformCounts(): array
    {
        return [
            'plans' => DB::table('saas_plans')->count(),
            'subscriptions' => DB::table('saas_subscriptions')->count(),
            'saas_payments' => DB::table('saas_payments')->count(),
            'intelligence_accesses' => DB::table('tenant_intelligence_accesses')->count(),
            'audits' => DB::table('audit_logs')->count(),
        ];
    }

    /** @return array<string, int> */
    private function rentalFinanceCounts(): array
    {
        return [
            'invoices' => DB::table('invoices')->count(),
            'invoice_lines' => DB::table('invoice_lines')->count(),
            'payments' => DB::table('payments')->count(),
            'payment_allocations' => DB::table('payment_allocations')->count(),
            'deposit_transactions' => DB::table('deposit_transactions')->count(),
            'expenses' => DB::table('expenses')->count(),
        ];
    }
}
