<?php

namespace Tests\Feature;

use App\Actions\PlatformBilling\AssignSaasSubscription;
use App\Actions\PlatformBilling\CreateSaasPlan;
use App\Actions\PlatformBilling\RecordSaasPayment;
use App\Actions\PlatformBilling\ReverseSaasPayment;
use App\Actions\PlatformBilling\TransitionSaasSubscription;
use App\Actions\PlatformBilling\UpdateSaasPlan;
use App\Enums\PlatformBilling\SaasPaymentEntryType;
use App\Enums\PlatformBilling\TenantSubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\PlatformBilling\SaasPayment;
use App\Models\PlatformBilling\SaasPlan;
use App\Models\PlatformBilling\SaasSubscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PlatformBillingDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_and_subscription_keep_an_immutable_price_snapshot_and_descriptive_features(): void
    {
        [$platform, $tenant] = $this->actors();
        $plan = $this->plan($platform, 'essential', '499.90', ['Gestion de flotte', 'Rapports']);
        $subscription = $this->subscription($platform, $tenant, $plan);

        $this->assertSame(['Gestion de flotte', 'Rapports'], $plan->features);
        $this->assertSame('499.90', $subscription->price_amount);
        $this->assertSame('monthly', $subscription->billing_interval->value);
        $this->assertSame('MAD', $subscription->currency);
        $this->assertSame('Renouvellement manuel de démonstration.', $subscription->admin_note);

        app(UpdateSaasPlan::class)->handle($plan, [
            'name' => 'Essentiel actualisé',
            'description' => 'Plan mensuel actualisé.',
            'price_amount' => '599.90',
            'currency' => 'MAD',
            'features' => ['Gestion de flotte', 'Rapports', 'Support'],
            'is_active' => true,
        ], $platform->id);

        $this->assertSame('499.90', $subscription->refresh()->price_amount);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.saas_plan.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.subscription.created', 'tenant_id' => $tenant->id]);
    }

    public function test_only_one_current_subscription_exists_and_status_never_changes_tenant_service_state(): void
    {
        [$platform, $tenant] = $this->actors();
        $firstPlan = $this->plan($platform, 'monthly-one');
        $secondPlan = $this->plan($platform, 'annual-two', '1200.00', [], 'annual');
        $subscription = $this->subscription($platform, $tenant, $firstPlan);

        $this->expectValidation(function () use ($platform, $tenant, $secondPlan): void {
            $this->subscription($platform, $tenant, $secondPlan);
        }, 'subscription');

        $subscription = app(TransitionSaasSubscription::class)->handle(
            $subscription,
            TenantSubscriptionStatus::PastDue,
            $platform->id,
        );
        $subscription = app(TransitionSaasSubscription::class)->handle(
            $subscription,
            TenantSubscriptionStatus::Suspended,
            $platform->id,
        );
        $subscription = app(TransitionSaasSubscription::class)->handle(
            $subscription,
            TenantSubscriptionStatus::Cancelled,
            $platform->id,
        );

        $this->assertSame(TenantStatus::Active, $tenant->refresh()->status);
        $this->expectValidation(fn () => app(TransitionSaasSubscription::class)->handle(
            $subscription,
            TenantSubscriptionStatus::Active,
            $platform->id,
        ), 'status');

        $replacement = $this->subscription($platform, $tenant, $secondPlan);
        $this->assertSame($secondPlan->id, $replacement->saas_plan_id);
        $this->assertSame(2, SaasSubscription::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_manual_payment_is_exact_idempotent_audited_and_separate_from_rental_finance(): void
    {
        [$platform, $tenant] = $this->actors();
        $subscription = $this->subscription($platform, $tenant, $this->plan($platform, 'payment-plan'));
        $rentalFinanceBefore = $this->rentalFinanceCounts();
        $data = [
            'payment_method' => 'bank_transfer',
            'amount' => '499.90',
            'reference' => 'RF-SAAS-DEMO-001',
            'idempotency_key' => 'saas-payment-demo-001',
            'occurred_at' => now()->toIso8601String(),
            'note' => 'Règlement manuel de démonstration.',
        ];

        $payment = app(RecordSaasPayment::class)->handle($subscription, $data, $platform->id);
        $retry = app(RecordSaasPayment::class)->handle($subscription, $data, $platform->id);

        $this->assertSame($payment->id, $retry->id);
        $this->assertSame('499.90', $payment->amount);
        $this->assertFalse(is_float($payment->amount));
        $this->assertSame('MAD', $payment->currency);
        $this->assertSame($tenant->id, $payment->tenant_id);
        $this->assertSame($rentalFinanceBefore, $this->rentalFinanceCounts());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'platform.saas_payment.recorded',
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_reversal_is_an_exact_append_only_entry_and_original_is_unchanged(): void
    {
        [$platform, $tenant] = $this->actors();
        $subscription = $this->subscription($platform, $tenant, $this->plan($platform, 'reversal-plan'));
        $payment = app(RecordSaasPayment::class)->handle($subscription, [
            'payment_method' => 'cheque',
            'amount' => '499.90',
            'reference' => 'RF-SAAS-ORIGINAL-001',
            'idempotency_key' => 'original-001',
            'note' => null,
        ], $platform->id);

        $reversal = app(ReverseSaasPayment::class)->handle($payment, [
            'reason' => 'Erreur de saisie administrative.',
            'reference' => 'RF-SAAS-REVERSAL-001',
            'idempotency_key' => 'reversal-001',
            'note' => 'Contrepassation validée manuellement.',
        ], $platform->id);

        $this->assertSame(SaasPaymentEntryType::Payment, $payment->refresh()->entry_type);
        $this->assertSame(SaasPaymentEntryType::Reversal, $reversal->entry_type);
        $this->assertSame($payment->id, $reversal->reversal_of_id);
        $this->assertSame($payment->amount, $reversal->amount);
        $this->assertSame($payment->currency, $reversal->currency);
        $this->assertSame($payment->payment_method, $reversal->payment_method);
        $this->assertSame(2, SaasPayment::query()->count());

        $this->expectValidation(fn () => app(ReverseSaasPayment::class)->handle($payment, [
            'reason' => 'Seconde tentative.',
            'idempotency_key' => 'reversal-002',
        ], $platform->id), 'payment');
    }

    public function test_actions_reject_client_supplied_scope_and_unknown_fields_without_side_effect(): void
    {
        [$platform, $tenant] = $this->actors();
        $subscription = $this->subscription($platform, $tenant, $this->plan($platform, 'strict-input'));

        $this->expectValidation(fn () => app(RecordSaasPayment::class)->handle($subscription, [
            'payment_method' => 'cash',
            'amount' => '100.00',
            'idempotency_key' => 'strict-001',
            'tenant_id' => $tenant->id,
        ], $platform->id), 'tenant_id');

        $this->assertDatabaseCount('saas_payments', 0);
    }

    public function test_postgresql_rejects_a_second_current_subscription_even_outside_the_action(): void
    {
        [$platform, $tenant] = $this->actors();
        $plan = $this->plan($platform, 'db-current');
        $subscription = $this->subscription($platform, $tenant, $plan);

        $this->expectException(QueryException::class);
        DB::table('saas_subscriptions')->insert([
            'tenant_id' => $tenant->id,
            'saas_plan_id' => $plan->id,
            'status' => 'suspended',
            'billing_interval' => 'monthly',
            'price_amount' => '499.90',
            'currency' => 'MAD',
            'starts_at' => $subscription->starts_at->addMonth(),
            'ends_at' => null,
            'trial_ends_at' => null,
            'next_renewal_at' => $subscription->starts_at->addMonths(2),
            'suspended_at' => now(),
            'cancelled_at' => null,
            'expired_at' => null,
            'admin_note' => null,
            'created_by' => $platform->id,
            'updated_by' => $platform->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_postgresql_rejects_subscription_snapshot_mutation(): void
    {
        [$platform, $tenant] = $this->actors();
        $subscription = $this->subscription($platform, $tenant, $this->plan($platform, 'db-snapshot'));

        $this->expectException(QueryException::class);
        DB::table('saas_subscriptions')->where('id', $subscription->id)->update(['price_amount' => '1.00']);
    }

    public function test_postgresql_rejects_payment_update_and_delete(): void
    {
        [$platform, $tenant] = $this->actors();
        $subscription = $this->subscription($platform, $tenant, $this->plan($platform, 'db-ledger'));
        $payment = app(RecordSaasPayment::class)->handle($subscription, [
            'payment_method' => 'cash',
            'amount' => '100.00',
            'idempotency_key' => 'db-ledger-001',
        ], $platform->id);

        $this->expectException(QueryException::class);
        DB::table('saas_payments')->where('id', $payment->id)->update(['note' => 'Mutation interdite']);
    }

    public function test_postgresql_rejects_payment_delete(): void
    {
        [$platform, $tenant] = $this->actors();
        $subscription = $this->subscription($platform, $tenant, $this->plan($platform, 'db-delete'));
        $payment = app(RecordSaasPayment::class)->handle($subscription, [
            'payment_method' => 'other',
            'amount' => '100.00',
            'idempotency_key' => 'db-delete-001',
        ], $platform->id);

        $this->expectException(QueryException::class);
        DB::table('saas_payments')->where('id', $payment->id)->delete();
    }

    /** @return array{User, Tenant} */
    private function actors(): array
    {
        $platform = User::factory()->create([
            'tenant_id' => null,
            'agency_id' => null,
            'role_id' => null,
            'is_platform_admin' => true,
            'is_active' => true,
        ]);
        $tenant = Tenant::factory()->create();
        $this->actingAs($platform);

        return [$platform, $tenant];
    }

    private function plan(
        User $platform,
        string $code,
        string $price = '499.90',
        array $features = [],
        string $interval = 'monthly',
    ): SaasPlan {
        return app(CreateSaasPlan::class)->handle([
            'code' => $code,
            'name' => ucfirst(str_replace('-', ' ', $code)),
            'description' => 'Plan SaaS de démonstration.',
            'billing_interval' => $interval,
            'price_amount' => $price,
            'currency' => 'MAD',
            'features' => $features,
            'is_active' => true,
        ], $platform->id);
    }

    private function subscription(
        User $platform,
        Tenant $tenant,
        SaasPlan $plan,
    ): SaasSubscription {
        return app(AssignSaasSubscription::class)->handle($tenant, $plan, [
            'status' => 'active',
            'starts_at' => now()->startOfDay()->toIso8601String(),
            'ends_at' => now()->addYear()->startOfDay()->toIso8601String(),
            'trial_ends_at' => null,
            'next_renewal_at' => now()->addMonth()->startOfDay()->toIso8601String(),
            'admin_note' => 'Renouvellement manuel de démonstration.',
        ], $platform->id);
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
            'rental_contracts' => DB::table('rental_contracts')->count(),
            'reservations' => DB::table('reservations')->count(),
            'customers' => DB::table('customers')->count(),
        ];
    }

    private function expectValidation(callable $callback, string $key): void
    {
        try {
            $callback();
            $this->fail('Une erreur de validation était attendue pour '.$key.'.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }
}
