<?php

namespace Tests\Feature;

use App\Actions\PlatformBilling\AssignSaasSubscription;
use App\Actions\PlatformBilling\CancelSaasPlanChange;
use App\Actions\PlatformBilling\CreateSaasPlan;
use App\Actions\PlatformBilling\IssueSaasInvoice;
use App\Actions\PlatformBilling\ProcessCmiCallback;
use App\Actions\PlatformBilling\ProcessSaasBilling;
use App\Actions\PlatformBilling\RecordSaasPayment;
use App\Actions\PlatformBilling\RequestSaasPlanChange;
use App\Actions\PlatformBilling\ReverseSaasPayment;
use App\Actions\PlatformBilling\StartCmiCheckout;
use App\Actions\PlatformBilling\TransitionSaasSubscription;
use App\Enums\PlatformBilling\TenantSubscriptionStatus;
use App\Models\PlatformBilling\SaasInvoice;
use App\Models\PlatformBilling\SaasPayment;
use App\Models\PlatformBilling\SaasPaymentAttempt;
use App\Models\PlatformBilling\SaasPlan;
use App\Models\PlatformBilling\SaasSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PlatformBilling\Cmi\CmiSignature;
use App\Support\PlatformBilling\SaasBillingPeriod;
use App\Support\PlatformBilling\TenantPlanAccess;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SaasSelfServiceBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-01-15T10:00:00Z'));
        config(['platform_billing.self_service_enabled' => true, 'platform_billing.renewals_enabled' => true]);
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    }

    public function test_pending_change_is_idempotent_and_preserves_current_plan_until_exact_settlement(): void
    {
        [$owner, $platform, $current] = $this->fixture();
        $plan = $this->plan($platform, '799.00');
        $pending = app(RequestSaasPlanChange::class)->handle($owner, $plan);
        $retry = app(RequestSaasPlanChange::class)->handle($owner, $plan);
        $this->assertSame($pending->id, $retry->id);
        $this->assertSame(TenantSubscriptionStatus::Active, $current->refresh()->status);
        $this->assertSame(TenantSubscriptionStatus::PendingPayment, $pending->status);
        $this->assertSame(1, SaasInvoice::query()->count());
        $invoice = $pending->invoices()->sole();
        $payment = $this->pay($platform, $pending, $invoice);
        $this->assertSame(TenantSubscriptionStatus::Cancelled, $current->refresh()->status);
        $this->assertSame(TenantSubscriptionStatus::Active, $pending->refresh()->status);
        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertSame($payment->id, $invoice->saas_payment_id);
        $this->assertSame('799.00', $payment->amount);
        $this->assertTrue($pending->next_renewal_at->equalTo($invoice->period_ends_at));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_free_plan_activates_without_a_fake_ledger_payment(): void
    {
        [$owner, $platform, $current] = $this->fixture();
        $new = app(RequestSaasPlanChange::class)->handle($owner, $this->plan($platform, '0.00'));
        $this->assertSame(TenantSubscriptionStatus::Active, $new->status);
        $this->assertSame(TenantSubscriptionStatus::Cancelled, $current->refresh()->status);
        $this->assertSame('paid', $new->invoices()->sole()->status);
        $this->assertDatabaseCount('saas_payments', 0);
    }

    public function test_downgrade_cannot_discard_existing_usage_and_reserves_new_quotas(): void
    {
        [$owner, $platform] = $this->fixture();
        $tooSmall = $this->plan($platform, '100.00', ['entitlements_configured' => true, 'max_users' => 0]);
        $this->reject(fn () => app(RequestSaasPlanChange::class)->handle($owner, $tooSmall));
        $fits = $this->plan($platform, '100.00', ['entitlements_configured' => true, 'max_users' => 1]);
        $pending = app(RequestSaasPlanChange::class)->handle($owner, $fits);
        $quota = app(TenantPlanAccess::class)->quota('users', $owner->tenant_id);
        $this->assertSame(1, $quota['limit']);
        $this->assertFalse($quota['allowed']);
        app(CancelSaasPlanChange::class)->handle($pending);
        $this->assertNull(app(TenantPlanAccess::class)->quota('users', $owner->tenant_id)['limit']);
    }

    public function test_inactive_currency_mismatch_and_same_plans_are_rejected(): void
    {
        [$owner, $platform, $current] = $this->fixture();
        foreach ([$current->plan, $this->plan($platform, '1.00', ['is_active' => false]),
            $this->plan($platform, '1.00', ['currency' => 'EUR'])] as $plan) {
            $this->reject(fn () => app(RequestSaasPlanChange::class)->handle($owner, $plan));
        }
        $this->assertDatabaseCount('saas_invoices', 0);
    }

    public function test_cancellation_and_expiration_void_the_invoice_without_changing_current_service(): void
    {
        [$owner, $platform, $current] = $this->fixture();
        $pending = app(RequestSaasPlanChange::class)->handle($owner, $this->plan($platform));
        $this->travelTo($pending->change_expires_at);
        app(ProcessSaasBilling::class)->handle();
        $this->assertSame(TenantSubscriptionStatus::Cancelled, $pending->refresh()->status);
        $this->assertSame('void', $pending->invoices()->sole()->status);
        $this->assertSame(TenantSubscriptionStatus::Active, $current->refresh()->status);
    }

    public function test_tenant_cannot_read_or_cancel_another_tenants_invoice_or_change(): void
    {
        [$owner, $platform] = $this->fixture();
        $pending = app(RequestSaasPlanChange::class)->handle($owner, $this->plan($platform));
        $invoice = $pending->invoices()->sole();
        $other = $this->createTenantOwner(['must_change_password' => false]);
        $this->actingAs($other)->get(route('tenant-saas-invoices.show', $invoice))->assertNotFound();
        $this->actingAs($other)->delete(route('tenant-saas-plan-change.destroy', $pending))->assertNotFound();
        $this->actingAs($owner)->get(route('tenant-saas-invoices.show', $invoice))->assertOk()->assertSee($invoice->number)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_http_requires_consent_and_recent_password_confirmation(): void
    {
        [$owner, $platform] = $this->fixture();
        $data = ['saas_plan_id' => $this->plan($platform)->id];
        $this->actingAs($owner)->post(route('tenant-saas-plan-change.store'), $data)->assertSessionHasErrors('terms_accepted');
        $this->withSession(['auth.password_confirmed_at' => 0])->post(route('tenant-saas-plan-change.store'),
            [...$data, 'terms_accepted' => 1])->assertRedirect(route('password.confirm'));
        $this->assertDatabaseCount('saas_invoices', 0);
    }

    public function test_renewals_are_opt_in_and_repeated_scheduler_runs_are_idempotent(): void
    {
        [$owner, $platform, $current] = $this->fixture();
        $this->travelTo($current->ends_at->addHour());
        app(ProcessSaasBilling::class)->handle();
        $this->assertDatabaseCount('saas_invoices', 0);
        $current->forceFill(['auto_renew' => true])->save();
        app(ProcessSaasBilling::class)->handle();
        app(ProcessSaasBilling::class)->handle();
        $this->assertDatabaseCount('saas_invoices', 1);
        $this->assertSame(TenantSubscriptionStatus::PastDue, $current->refresh()->status);
        $this->assertTrue(app(TenantPlanAccess::class)->state($owner->tenant_id)['available']);
        $this->assertDatabaseCount('saas_invoice_events', 2);
    }

    public function test_unpaid_renewal_suspends_then_exact_payment_restores_the_billing_period(): void
    {
        [$owner, $platform, $current] = $this->fixture();
        $current->forceFill(['auto_renew' => true])->save();
        $this->travelTo($current->ends_at->addDays(8));
        app(ProcessSaasBilling::class)->handle();
        $invoice = $current->invoices()->sole();
        $this->assertTrue($current->refresh()->billing_suspended);
        $this->assertFalse(app(TenantPlanAccess::class)->state($owner->tenant_id)['available']);
        $this->pay($platform, $current, $invoice);
        $this->assertSame(TenantSubscriptionStatus::Active, $current->refresh()->status);
        $this->assertFalse($current->billing_suspended);
        $this->assertTrue($current->next_renewal_at->equalTo($invoice->period_ends_at));
    }

    public function test_disabling_renewal_does_not_erase_an_already_issued_debt(): void
    {
        [$owner, $platform, $current] = $this->fixture();
        $current->forceFill(['auto_renew' => true])->save();
        $this->travelTo($current->ends_at->addHour());
        app(ProcessSaasBilling::class)->handle();
        $current->refresh()->forceFill(['auto_renew' => false])->save();
        $this->travelTo($current->ends_at->addDays(8));
        app(ProcessSaasBilling::class)->handle();
        $this->assertTrue($current->refresh()->billing_suspended);
        $this->assertSame('open', $current->invoices()->sole()->status);
    }

    public function test_expired_trial_stays_suspended_on_repeated_scheduler_runs(): void
    {
        [$owner, $platform, $current] = $this->fixture();
        app(TransitionSaasSubscription::class)->handle($current, TenantSubscriptionStatus::Cancelled, $platform->id);
        $trial = app(AssignSaasSubscription::class)->handle(Tenant::query()->findOrFail($owner->tenant_id), $current->plan, [
            'status' => 'trialing', 'starts_at' => '2026-01-15T00:00:00Z',
            'trial_ends_at' => '2026-01-16T00:00:00Z', 'ends_at' => '2026-02-16T00:00:00Z',
            'next_renewal_at' => '2026-01-16T00:00:00Z',
        ], $platform->id);
        $trial->forceFill(['auto_renew' => true])->save();
        $this->travelTo(CarbonImmutable::parse('2026-01-16T01:00:00Z'));
        app(ProcessSaasBilling::class)->handle();
        app(ProcessSaasBilling::class)->handle();
        $this->assertSame(TenantSubscriptionStatus::Suspended, $trial->refresh()->status);
        $this->assertTrue($trial->billing_suspended);
        $this->assertFalse(app(TenantPlanAccess::class)->state($owner->tenant_id)['available']);
    }

    public function test_wrong_invoice_amount_and_payment_on_old_plan_during_change_are_rejected(): void
    {
        [$owner, $platform, $current] = $this->fixture();
        $pending = app(RequestSaasPlanChange::class)->handle($owner, $this->plan($platform));
        $invoice = $pending->invoices()->sole();
        $this->reject(fn () => app(RecordSaasPayment::class)->handle($pending, [
            'payment_method' => 'cash', 'amount' => '0.01', 'saas_invoice_id' => $invoice->id,
            'idempotency_key' => (string) Str::uuid(),
        ], $platform->id));
        $this->enableCmi();
        $this->reject(fn () => app(StartCmiCheckout::class)->handle($current, $owner, (string) Str::uuid()));
        $this->assertDatabaseCount('saas_payments', 0);
    }

    public function test_payment_and_worker_cannot_clear_an_administrative_suspension(): void
    {
        [$owner, $platform, $current] = $this->fixture();
        $current->forceFill(['auto_renew' => true])->save();
        $this->travelTo($current->ends_at->addDays(8));
        app(ProcessSaasBilling::class)->handle();
        $invoice = $current->invoices()->sole();
        app(TransitionSaasSubscription::class)->handle($current, TenantSubscriptionStatus::Suspended, $platform->id);
        $this->assertFalse($current->refresh()->billing_suspended);
        $this->reject(fn () => $this->pay($platform, $current, $invoice));
        $this->enableCmi();
        $this->reject(fn () => app(StartCmiCheckout::class)->handle($current, $owner, (string) Str::uuid()));
        app(ProcessSaasBilling::class)->handle();
        $this->assertSame(TenantSubscriptionStatus::Suspended, $current->refresh()->status);
        $this->assertDatabaseCount('saas_payments', 0);
    }

    public function test_cmi_checkout_serializes_manual_settlement_and_callback_is_idempotent(): void
    {
        [$owner, $platform] = $this->fixture();
        $pending = app(RequestSaasPlanChange::class)->handle($owner, $this->plan($platform));
        $invoice = $pending->invoices()->sole();
        $this->enableCmi();
        $attempt = app(StartCmiCheckout::class)->handle($pending, $owner, (string) Str::uuid());
        $this->assertSame($invoice->id, $attempt->saas_invoice_id);
        $this->reject(fn () => $this->pay($platform, $pending, $invoice));
        $callback = $this->signedCmiCallback($attempt);
        $this->assertTrue(app(ProcessCmiCallback::class)->handle($callback)['accepted']);
        $this->assertTrue(app(ProcessCmiCallback::class)->handle($callback)['accepted']);
        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertDatabaseCount('saas_payments', 1);
    }

    public function test_callback_after_administrative_hold_is_declined_with_reconciliation_reference(): void
    {
        [$owner, $platform, $current] = $this->fixture();
        $pending = app(RequestSaasPlanChange::class)->handle($owner, $this->plan($platform));
        $this->enableCmi();
        $attempt = app(StartCmiCheckout::class)->handle($pending, $owner, (string) Str::uuid());
        app(TransitionSaasSubscription::class)->handle($current, TenantSubscriptionStatus::Suspended, $platform->id);
        $this->assertFalse(app(ProcessCmiCallback::class)->handle($this->signedCmiCallback($attempt))['accepted']);
        $this->assertSame('TEST-TX-1', $attempt->refresh()->gateway_transaction_id);
        $this->assertDatabaseCount('saas_payments', 0);
    }

    public function test_replaying_an_invalid_callback_never_becomes_accepted_after_a_valid_payment(): void
    {
        [$owner, $platform, $current] = $this->fixture();
        $this->enableCmi();
        $attempt = app(StartCmiCheckout::class)->handle($current, $owner, (string) Str::uuid());
        $valid = $this->signedCmiCallback($attempt);
        $invalid = [...$valid, 'HASH' => 'invalid'];
        $this->assertFalse(app(ProcessCmiCallback::class)->handle($invalid)['accepted']);
        $this->assertTrue(app(ProcessCmiCallback::class)->handle($valid)['accepted']);
        $this->assertFalse(app(ProcessCmiCallback::class)->handle($invalid)['accepted']);
    }

    public function test_signed_replay_for_another_transaction_cannot_reuse_a_paid_order(): void
    {
        [$owner, $platform, $current] = $this->fixture();
        $this->enableCmi();
        $attempt = app(StartCmiCheckout::class)->handle($current, $owner, (string) Str::uuid());
        $valid = $this->signedCmiCallback($attempt);
        $this->assertTrue(app(ProcessCmiCallback::class)->handle($valid)['accepted']);
        $altered = [...$valid, 'TransId' => 'DIFFERENT-TX'];
        unset($altered['HASH']);
        $altered['HASH'] = app(CmiSignature::class)->sign($altered, 'store-test-secret');
        $this->assertFalse(app(ProcessCmiCallback::class)->handle($altered)['accepted']);
        $this->assertDatabaseCount('saas_payments', 1);
    }

    public function test_expiration_boundary_is_closed_and_a_new_attempt_can_start(): void
    {
        [$owner, $platform, $current] = $this->fixture();
        $this->enableCmi();
        $attempt = app(StartCmiCheckout::class)->handle($current, $owner, (string) Str::uuid());
        $this->travelTo($attempt->expires_at);
        $this->assertFalse(app(ProcessCmiCallback::class)->handle($this->signedCmiCallback($attempt))['accepted']);
        $next = app(StartCmiCheckout::class)->handle($current, $owner, (string) Str::uuid());
        $this->assertNotSame($attempt->id, $next->id);
        $this->assertSame('expired', $attempt->refresh()->status->value);
    }

    public function test_reversal_keeps_original_ledger_and_reopens_renewal_for_repayment(): void
    {
        [$owner, $platform, $current] = $this->fixture();
        $current->forceFill(['auto_renew' => true])->save();
        $this->travelTo($current->ends_at->addHour());
        app(ProcessSaasBilling::class)->handle();
        $invoice = $current->invoices()->sole();
        $payment = $this->pay($platform, $current, $invoice);
        app(ReverseSaasPayment::class)->handle($payment, [
            'reason' => 'Correction du règlement de test', 'idempotency_key' => (string) Str::uuid(),
        ], $platform->id);
        $this->assertSame('open', $invoice->refresh()->status);
        $this->assertTrue($current->refresh()->billing_suspended);
        $replacement = $this->pay($platform, $current, $invoice);
        $this->assertSame($replacement->id, $invoice->refresh()->saas_payment_id);
        $this->assertDatabaseCount('saas_payments', 3);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_invoice_snapshot_and_events_are_immutable_even_with_raw_sql(): void
    {
        [$owner, $platform] = $this->fixture();
        $pending = app(RequestSaasPlanChange::class)->handle($owner, $this->plan($platform));
        $invoice = $pending->invoices()->sole();
        foreach ([
            fn () => DB::table('saas_invoices')->where('id', $invoice->id)->update(['amount' => '0.01']),
            fn () => DB::table('saas_invoice_events')->where('saas_invoice_id', $invoice->id)->delete(),
            fn () => DB::table('saas_invoices')->where('id', $invoice->id)->delete(),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Expected PostgreSQL immutability guard.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', (string) $exception->getCode());
            }
        }
    }

    public function test_invoice_events_enqueue_once_on_the_transactional_database_queue(): void
    {
        [$owner, $platform, $current] = $this->fixture();
        $invoice = app(IssueSaasInvoice::class)->handle($current, CarbonImmutable::now());
        $same = app(IssueSaasInvoice::class)->handle($current, CarbonImmutable::now());
        $this->assertSame($invoice->id, $same->id);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'saas-billing')->count());
        $payload = json_decode(DB::table('jobs')->where('queue', 'saas-billing')->value('payload'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString($invoice->id, $payload['data']['command']);
    }

    public function test_queue_failure_rolls_back_invoice_and_event_instead_of_losing_the_email(): void
    {
        [$owner, $platform, $current] = $this->fixture();
        config(['queue.connections.saas_billing.table' => 'missing_invoice_jobs']);
        try {
            app(IssueSaasInvoice::class)->handle($current, CarbonImmutable::now());
            $this->fail('Expected the database queue write to fail.');
        } catch (QueryException) {
            $this->assertDatabaseCount('saas_invoices', 0);
            $this->assertDatabaseCount('saas_invoice_events', 0);
        }
    }

    public function test_calendar_periods_do_not_overflow_short_months_or_leap_years(): void
    {
        [$owner, $platform, $current] = $this->fixture();
        $periods = app(SaasBillingPeriod::class);
        $this->assertSame('2026-02-28', $periods->end($current, CarbonImmutable::parse('2026-01-31'))->toDateString());
        $annual = new SaasSubscription;
        $annual->forceFill(['billing_interval' => 'annual']);
        $this->assertSame('2029-02-28', $periods->end($annual, CarbonImmutable::parse('2028-02-29'))->toDateString());
    }

    public function test_structural_audit_detects_disabled_triggers_weakened_checks_and_non_unique_indexes(): void
    {
        $this->assertSame(0, Artisan::call('saas:audit-billing', ['--json' => true]));
        DB::beginTransaction();
        try {
            DB::statement('ALTER TABLE saas_invoices DISABLE TRIGGER saas_invoices_guard');
            DB::statement('ALTER TABLE saas_invoices DROP CONSTRAINT saas_invoice_amount_check');
            DB::statement('ALTER TABLE saas_invoices ADD CONSTRAINT saas_invoice_amount_check CHECK (true)');
            DB::statement('DROP INDEX saas_one_pending_change_idx');
            DB::statement('CREATE INDEX saas_one_pending_change_idx ON saas_subscriptions (id)');
            $this->assertSame(1, Artisan::call('saas:audit-billing', ['--json' => true]));
            $checks = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)['checks'];
            $this->assertFalse($checks['saas_invoices_guard']);
            $this->assertFalse($checks['saas_invoice_amount_check']);
            $this->assertFalse($checks['saas_one_pending_change_idx']);
        } finally {
            DB::rollBack();
        }
        $this->assertSame(0, Artisan::call('saas:audit-billing', ['--json' => true]));
    }

    /** @return array{User, User, SaasSubscription} */
    private function fixture(): array
    {
        $owner = $this->createTenantOwner(['must_change_password' => false]);
        $platform = User::factory()->create(['tenant_id' => null, 'agency_id' => null, 'is_platform_admin' => true, 'is_active' => true]);
        $subscription = app(AssignSaasSubscription::class)->handle(Tenant::query()->findOrFail($owner->tenant_id), $this->plan($platform), [
            'status' => 'active', 'starts_at' => '2026-01-01T00:00:00Z',
            'ends_at' => '2026-02-01T00:00:00Z', 'next_renewal_at' => '2026-02-01T00:00:00Z',
        ], $platform->id);

        return [$owner, $platform, $subscription];
    }

    private function plan(User $platform, string $price = '499.00', array $extra = []): SaasPlan
    {
        return app(CreateSaasPlan::class)->handle([
            'code' => 'billing-'.Str::lower(Str::random(10)), 'name' => 'Formule de test',
            'billing_interval' => 'monthly', 'price_amount' => $price, 'currency' => 'MAD',
            'is_active' => true, ...$extra,
        ], $platform->id);
    }

    private function pay(User $platform, SaasSubscription $subscription, SaasInvoice $invoice): SaasPayment
    {
        return app(RecordSaasPayment::class)->handle($subscription, [
            'payment_method' => 'bank_transfer', 'amount' => $invoice->amount,
            'saas_invoice_id' => $invoice->id, 'idempotency_key' => (string) Str::uuid(),
        ], $platform->id);
    }

    private function reject(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected a domain validation error.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    private function enableCmi(): void
    {
        config([
            'platform_billing.cmi.enabled' => true, 'platform_billing.cmi.mode' => 'sandbox',
            'platform_billing.cmi.endpoint' => 'https://testpayment.cmi.co.ma/fim/est3Dgate',
            'platform_billing.cmi.merchant_id' => 'merchant-test-123',
            'platform_billing.cmi.store_key' => 'store-test-secret',
            'platform_billing.cmi.merchant_kit_version' => 'test-kit-ver3',
        ]);
    }

    private function signedCmiCallback(SaasPaymentAttempt $attempt): array
    {
        $data = ['amount' => $attempt->amount, 'clientid' => 'merchant-test-123', 'currency' => '504',
            'oid' => $attempt->merchant_order_id, 'ProcReturnCode' => '00', 'TransId' => 'TEST-TX-1'];
        $data['HASH'] = app(CmiSignature::class)->sign($data, 'store-test-secret');

        return $data;
    }
}
