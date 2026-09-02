<?php

namespace Tests\Feature;

use App\Actions\PlatformBilling\AssignSaasSubscription;
use App\Actions\PlatformBilling\CreateSaasPlan;
use App\Enums\PlatformBilling\SaasPaymentAttemptStatus;
use App\Models\PlatformBilling\SaasPayment;
use App\Models\PlatformBilling\SaasPaymentAttempt;
use App\Models\PlatformBilling\SaasPaymentGatewayEvent;
use App\Models\PlatformBilling\SaasPlan;
use App\Models\PlatformBilling\SaasSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Support\PlatformBilling\Cmi\CmiSignature;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaasPriorityThreeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_public_home_pricing_and_subscription_pages_publish_active_plans_without_registration(): void
    {
        [$owner, $subscription, $plan] = $this->billingFixture();

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Une vue claire de chaque véhicule, contrat et décision.')
            ->assertSee($plan->name)
            ->assertDontSee('name="register"', false);
        $this->get(route('pricing'))->assertOk()->assertSee($plan->name)->assertSee('499,90');
        $this->get(route('subscription.public'))->assertOk()->assertSee('Une activation accompagnée');
    }

    public function test_unverified_users_are_sent_to_verification_before_business_or_platform_pages(): void
    {
        $owner = $this->createTenantOwner(['email_verified_at' => null, 'must_change_password' => false]);
        $platform = User::factory()->create([
            'tenant_id' => null,
            'agency_id' => null,
            'is_platform_admin' => true,
            'email_verified_at' => null,
        ]);

        $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
        $this->actingAs($platform)->get(route('platform.dashboard'))->assertRedirect(route('verification.notice'));
    }

    public function test_authentication_notifications_are_branded_and_unknown_reset_addresses_are_not_enumerated(): void
    {
        Notification::fake();
        $user = $this->createTenantOwner(['email_verified_at' => null]);

        $this->actingAs($user)->post(route('verification.send'))->assertSessionHas('status', 'verification-link-sent');
        Notification::assertSentTo($user, VerifyEmailNotification::class);

        auth()->logout();
        $known = $this->post(route('password.email'), ['email' => $user->email]);
        $knownStatus = session('status');
        $this->flushSession();
        $unknown = $this->post(route('password.email'), ['email' => 'absent@example.test']);

        $known->assertRedirect();
        $unknown->assertRedirect();
        $this->assertSame($knownStatus, session('status'));
        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_email_password_reset_revokes_existing_sessions_and_is_audited(): void
    {
        $user = $this->createTenantOwner();
        DB::table('sessions')->insert([
            'id' => 'session-before-email-reset',
            'user_id' => $user->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
        $token = Password::createToken($user);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'SecurePassword2026',
            'password_confirmation' => 'SecurePassword2026',
        ])->assertRedirect(route('login'))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'session-before-email-reset']);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $user->tenant_id,
            'auditable_id' => $user->getKey(),
            'action' => 'user.password_reset.email',
        ]);
    }

    public function test_cmi_checkout_is_fail_closed_until_merchant_configuration_is_complete(): void
    {
        [$owner, $subscription] = $this->billingFixture();

        $this->actingAs($owner)
            ->from(route('tenant-saas-account.show'))
            ->post(route('tenant-saas-checkout.store', $subscription), [
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('tenant-saas-account.show'))
            ->assertSessionHasErrors('payment');

        $this->assertSame(0, SaasPaymentAttempt::query()->count());
    }

    public function test_cmi_checkout_posts_only_server_snapshots_and_a_signed_callback_records_one_payment(): void
    {
        $this->enableCmi();
        [$owner, $subscription] = $this->billingFixture();
        $idempotencyKey = (string) Str::uuid();

        $response = $this->actingAs($owner)->post(route('tenant-saas-checkout.store', $subscription), [
            'idempotency_key' => $idempotencyKey,
            'amount' => '0.01',
            'currency' => 'USD',
            'tenant_id' => 999,
        ]);
        $attempt = SaasPaymentAttempt::query()->sole();
        $response->assertRedirect(route('tenant-saas-checkout.show', $attempt));
        $this->assertSame('499.90', $attempt->amount);
        $this->assertSame('MAD', $attempt->currency);
        $this->assertSame($owner->tenant_id, $attempt->tenant_id);

        $this->actingAs($owner)->get(route('tenant-saas-checkout.show', $attempt))
            ->assertOk()
            ->assertSee('name="HASH"', false)
            ->assertSee('name="amount" value="499.90"', false)
            ->assertSee('https://testpayment.cmi.co.ma/fim/est3Dgate', false)
            ->assertDontSee('card_number');

        $callback = $this->signedCallback($attempt, 'TX-CMI-0001');
        $this->post(route('billing.cmi.callback'), $callback)
            ->assertOk()
            ->assertSeeText('ACTION=POSTAUTH');
        $this->post(route('billing.cmi.callback'), $callback)->assertOk();

        $attempt->refresh();
        $this->assertSame(SaasPaymentAttemptStatus::Paid, $attempt->status);
        $this->assertSame('TX-CMI-0001', $attempt->gateway_transaction_id);
        $this->assertSame(1, SaasPaymentGatewayEvent::query()->count());
        $this->assertSame(1, SaasPayment::query()->where('payment_method', 'cmi')->count());
        $this->assertDatabaseHas('saas_payments', [
            'tenant_id' => $owner->tenant_id,
            'saas_subscription_id' => $subscription->getKey(),
            'amount' => '499.90',
            'currency' => 'MAD',
            'reference' => 'CMI:TX-CMI-0001',
        ]);
        $this->assertSame('active', $subscription->refresh()->status->value);
        $this->assertNotNull($subscription->next_renewal_at);
    }

    public function test_invalid_or_amount_mismatched_cmi_callbacks_never_create_a_payment(): void
    {
        $this->enableCmi();
        [$owner, $subscription] = $this->billingFixture();
        $attempt = $this->startAttempt($owner, $subscription);

        $invalid = $this->signedCallback($attempt, 'TX-CMI-BAD');
        $invalid['HASH'] = 'invalid-signature';
        $this->post(route('billing.cmi.callback'), $invalid)->assertStatus(422);
        $this->assertSame(SaasPaymentAttemptStatus::Pending, $attempt->refresh()->status);
        $this->assertSame(0, SaasPayment::query()->count());

        $tampered = $this->signedCallback($attempt, 'TX-CMI-TAMPER', '1.00');
        $this->post(route('billing.cmi.callback'), $tampered)->assertOk()->assertSeeText('ACTION=DECLINE');
        $this->assertSame(SaasPaymentAttemptStatus::Failed, $attempt->refresh()->status);
        $this->assertSame('AMOUNT_MISMATCH', $attempt->gateway_response_code);
        $this->assertSame(0, SaasPayment::query()->count());
    }

    public function test_browser_return_never_marks_an_attempt_as_paid_and_tenants_cannot_cross_scope(): void
    {
        $this->enableCmi();
        [$owner, $subscription] = $this->billingFixture();
        $attempt = $this->startAttempt($owner, $subscription);
        $otherOwner = $this->createTenantOwner();

        $this->get(route('billing.cmi.return', ['attempt' => $attempt, 'result' => 'success']))
            ->assertOk()
            ->assertSee('Confirmation en cours');
        $this->assertSame(SaasPaymentAttemptStatus::Pending, $attempt->refresh()->status);
        $this->assertSame(0, SaasPayment::query()->count());

        $this->actingAs($otherOwner)->post(route('tenant-saas-checkout.store', $subscription), [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertNotFound();
        $this->actingAs($otherOwner)->get(route('tenant-saas-checkout.show', $attempt))->assertNotFound();
    }

    public function test_platform_admin_can_read_global_audit_log_but_tenant_owner_cannot(): void
    {
        [$owner] = $this->billingFixture();
        $platform = User::factory()->create([
            'tenant_id' => null,
            'agency_id' => null,
            'is_platform_admin' => true,
            'is_active' => true,
        ]);

        $this->actingAs($platform)->get(route('platform.audit-logs.index'))->assertOk();
        $this->actingAs($owner)->get(route('platform.audit-logs.index'))->assertForbidden();
    }

    /** @return array{User, SaasSubscription, SaasPlan} */
    private function billingFixture(): array
    {
        $owner = $this->createTenantOwner(['must_change_password' => false]);
        $platform = User::factory()->create([
            'tenant_id' => null,
            'agency_id' => null,
            'is_platform_admin' => true,
            'is_active' => true,
        ]);
        $plan = app(CreateSaasPlan::class)->handle([
            'code' => 'priority-three-plan-'.Str::lower(Str::random(6)),
            'name' => 'Plan Priorité 3',
            'description' => 'Offre de validation du parcours SaaS.',
            'billing_interval' => 'monthly',
            'price_amount' => '499.90',
            'currency' => 'MAD',
            'features' => ['Gestion de flotte', 'Rapports', 'Assistances'],
            'is_active' => true,
        ], $platform->getKey());
        $tenant = Tenant::query()->findOrFail($owner->tenant_id);
        $subscription = app(AssignSaasSubscription::class)->handle($tenant, $plan, [
            'status' => 'active',
            'starts_at' => now()->subDay()->toIso8601String(),
            'ends_at' => now()->addYear()->toIso8601String(),
            'trial_ends_at' => null,
            'next_renewal_at' => now()->addMonth()->toIso8601String(),
            'admin_note' => 'Fixture priorité 3.',
        ], $platform->getKey());

        return [$owner, $subscription, $plan];
    }

    private function enableCmi(): void
    {
        config([
            'platform_billing.cmi.enabled' => true,
            'platform_billing.cmi.mode' => 'sandbox',
            'platform_billing.cmi.endpoint' => 'https://testpayment.cmi.co.ma/fim/est3Dgate',
            'platform_billing.cmi.merchant_id' => 'merchant-test-123',
            'platform_billing.cmi.store_key' => 'store-test-secret',
            'platform_billing.cmi.merchant_kit_version' => 'test-kit-ver3',
            'platform_billing.cmi.success_acknowledgement' => 'ACTION=POSTAUTH',
            'platform_billing.cmi.failure_acknowledgement' => 'ACTION=DECLINE',
        ]);
    }

    private function startAttempt(User $owner, SaasSubscription $subscription): SaasPaymentAttempt
    {
        $this->actingAs($owner)->post(route('tenant-saas-checkout.store', $subscription), [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();

        return SaasPaymentAttempt::query()->latest()->firstOrFail();
    }

    /** @return array<string, string> */
    private function signedCallback(
        SaasPaymentAttempt $attempt,
        string $transactionId,
        ?string $amount = null,
    ): array {
        $parameters = [
            'amount' => $amount ?? $attempt->amount,
            'clientid' => 'merchant-test-123',
            'currency' => '504',
            'oid' => $attempt->merchant_order_id,
            'ProcReturnCode' => '00',
            'rnd' => 'callback-random',
            'TransId' => $transactionId,
        ];
        $parameters['HASH'] = app(CmiSignature::class)->sign($parameters, 'store-test-secret');

        return $parameters;
    }
}
