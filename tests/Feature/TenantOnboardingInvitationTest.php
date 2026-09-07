<?php

namespace Tests\Feature;

use App\Actions\Platform\CreateTenantOnboardingInvitation;
use App\Actions\PlatformBilling\CreateSaasPlan;
use App\Enums\IntelligenceCapability;
use App\Enums\Platform\TenantOnboardingInvitationStatus;
use App\Models\Platform\TenantOnboardingInvitation;
use App\Models\PlatformBilling\SaasPlan;
use App\Models\User;
use App\Notifications\TenantOnboardingInvitationNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class TenantOnboardingInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_only_platform_admin_can_send_an_invitation_and_delivery_never_exposes_the_token_in_storage(): void
    {
        Notification::fake();
        $platform = $this->platformAdmin();
        $plan = $this->plan($platform);
        $tenantUser = User::factory()->create(['is_platform_admin' => false]);
        $payload = [
            'email' => 'future.owner@example.test',
            'saas_plan_id' => $plan->getKey(),
            'trial_days' => 14,
            'expires_in_hours' => 72,
        ];

        $this->post(route('platform.onboarding-invitations.store'), $payload)->assertRedirect(route('login'));
        $this->actingAs($tenantUser)->post(route('platform.onboarding-invitations.store'), $payload)->assertForbidden();
        $this->actingAs($platform)->post(route('platform.onboarding-invitations.store'), $payload)
            ->assertRedirect(route('platform.onboarding-invitations.index'));

        $invitation = TenantOnboardingInvitation::query()->sole();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $invitation->getRawOriginal('secret_hash'));
        $this->assertArrayNotHasKey('secret_hash', $invitation->toArray());
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.onboarding_invitation.created']);
        Notification::assertSentOnDemand(TenantOnboardingInvitationNotification::class);
        $this->assertFalse(Route::has('register'));
    }

    public function test_valid_invitation_creates_verified_owner_agency_trial_and_entitlement_access_atomically(): void
    {
        $platform = $this->platformAdmin();
        $plan = $this->plan($platform);
        $result = app(CreateTenantOnboardingInvitation::class)->handle([
            'email' => 'invitee@example.test',
            'saas_plan_id' => $plan->getKey(),
            'trial_days' => 21,
            'expires_in_hours' => 48,
        ], $platform->getKey());
        $invitation = $result['invitation'];
        $token = $result['token'];
        $showUrl = URL::temporarySignedRoute('onboarding-invitations.show', $invitation->expires_at, [
            'invitation' => $invitation->getKey(),
            'token' => $token,
        ]);
        $acceptUrl = URL::temporarySignedRoute('onboarding-invitations.accept', $invitation->expires_at, [
            'invitation' => $invitation->getKey(),
            'token' => $token,
        ]);

        $this->get($showUrl)
            ->assertOk()
            ->assertSee('invitee@example.test')
            ->assertSee('21 jours')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->post($acceptUrl, $this->acceptancePayload())
            ->assertRedirect(route('onboarding.index'));

        $owner = User::query()->where('email', 'invitee@example.test')->sole();
        $this->assertAuthenticatedAs($owner);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertFalse($owner->must_change_password);
        $this->assertTrue($owner->isTenantOwner());
        $this->assertDatabaseHas('tenants', ['id' => $owner->tenant_id, 'slug' => 'atlas-invitation']);
        $this->assertDatabaseHas('agencies', ['tenant_id' => $owner->tenant_id, 'code' => 'CASA']);
        $this->assertDatabaseHas('saas_subscriptions', [
            'tenant_id' => $owner->tenant_id,
            'saas_plan_id' => $plan->getKey(),
            'status' => 'trialing',
        ]);
        $subscription = $plan->subscriptions()->where('tenant_id', $owner->tenant_id)->sole();
        $this->assertSame($plan->refresh()->entitlements, $subscription->entitlements);
        $this->assertSame(21, (int) round($subscription->starts_at->diffInDays($subscription->trial_ends_at)));
        $this->assertDatabaseHas('tenant_intelligence_accesses', [
            'tenant_id' => $owner->tenant_id,
            'capability' => IntelligenceCapability::DemandForecast->value,
            'enabled' => true,
        ]);
        $this->assertDatabaseHas('tenant_intelligence_accesses', [
            'tenant_id' => $owner->tenant_id,
            'capability' => IntelligenceCapability::VehicleDamage->value,
            'enabled' => false,
        ]);
        $this->assertSame(TenantOnboardingInvitationStatus::Accepted, $invitation->refresh()->status);
        $this->assertSame($owner->tenant_id, $invitation->accepted_tenant_id);

        $this->get(route('onboarding.index'))
            ->assertOk()
            ->assertSee('Démarrage guidé')
            ->assertSee('2 étapes sur 7');
    }

    public function test_log_mailer_is_rejected_before_an_invitation_is_persisted_and_expiration_is_scheduled(): void
    {
        Notification::fake();
        config(['mail.default' => 'log']);
        $platform = $this->platformAdmin();
        $plan = $this->plan($platform);

        $this->actingAs($platform)->post(route('platform.onboarding-invitations.store'), [
            'email' => 'never.logged@example.test',
            'saas_plan_id' => $plan->getKey(),
            'trial_days' => 14,
            'expires_in_hours' => 72,
        ])->assertRedirect()->assertSessionHasErrors('email');

        $this->assertDatabaseCount('tenant_onboarding_invitations', 0);
        Notification::assertNothingSent();
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'onboarding:expire-invitations'));
        $this->assertNotNull($event);
        $this->assertSame('Africa/Casablanca', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_failover_mailer_containing_a_log_transport_is_also_rejected(): void
    {
        Notification::fake();
        config([
            'mail.default' => 'safe-looking-failover',
            'mail.mailers.safe-looking-failover' => [
                'transport' => 'failover',
                'mailers' => ['smtp', 'log'],
            ],
        ]);
        $platform = $this->platformAdmin();
        $plan = $this->plan($platform);

        $this->actingAs($platform)->post(route('platform.onboarding-invitations.store'), [
            'email' => 'never.failed-over-to-log@example.test',
            'saas_plan_id' => $plan->getKey(),
            'trial_days' => 14,
            'expires_in_hours' => 72,
        ])->assertRedirect()->assertSessionHasErrors('email');

        $this->assertDatabaseCount('tenant_onboarding_invitations', 0);
        Notification::assertNothingSent();
    }

    public function test_invitation_from_a_deactivated_platform_administrator_cannot_be_accepted(): void
    {
        $platform = $this->platformAdmin();
        $plan = $this->plan($platform);
        $result = app(CreateTenantOnboardingInvitation::class)->handle([
            'email' => 'revoked-authority@example.test',
            'saas_plan_id' => $plan->getKey(),
            'trial_days' => 14,
            'expires_in_hours' => 72,
        ], $platform->getKey());
        $platform->forceFill(['is_active' => false])->save();
        $showUrl = URL::temporarySignedRoute(
            'onboarding-invitations.show',
            $result['invitation']->expires_at,
            ['invitation' => $result['invitation']->getKey(), 'token' => $result['token']],
        );
        $acceptUrl = URL::temporarySignedRoute(
            'onboarding-invitations.accept',
            $result['invitation']->expires_at,
            ['invitation' => $result['invitation']->getKey(), 'token' => $result['token']],
        );

        $this->get($showUrl)->assertGone();
        $this->post($acceptUrl, $this->acceptancePayload())
            ->assertRedirect()
            ->assertSessionHasErrors('invitation');
        $this->assertDatabaseCount('tenants', 0);
        $this->assertSame(TenantOnboardingInvitationStatus::Pending, $result['invitation']->refresh()->status);
    }

    public function test_wrong_token_replay_and_expiration_are_rejected_without_creating_a_second_tenant(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-03T09:00:00Z'));
        $platform = $this->platformAdmin();
        $plan = $this->plan($platform);
        $first = app(CreateTenantOnboardingInvitation::class)->handle([
            'email' => 'secure@example.test',
            'saas_plan_id' => $plan->getKey(),
            'trial_days' => 14,
            'expires_in_hours' => 1,
        ], $platform->getKey());
        $wrongUrl = URL::temporarySignedRoute('onboarding-invitations.show', $first['invitation']->expires_at, [
            'invitation' => $first['invitation']->getKey(),
            'token' => str_repeat('x', 80),
        ]);
        $this->get($wrongUrl)->assertNotFound();

        $acceptUrl = URL::temporarySignedRoute('onboarding-invitations.accept', $first['invitation']->expires_at, [
            'invitation' => $first['invitation']->getKey(),
            'token' => $first['token'],
        ]);
        $this->post($acceptUrl, $this->acceptancePayload())->assertRedirect(route('onboarding.index'));
        Auth::logout();
        $replay = $this->acceptancePayload();
        $replay['company_slug'] = 'atlas-replay';
        $this->post($acceptUrl, $replay)->assertRedirect()->assertSessionHasErrors('invitation');
        $this->assertDatabaseCount('tenants', 1);

        $second = app(CreateTenantOnboardingInvitation::class)->handle([
            'email' => 'expired@example.test',
            'saas_plan_id' => $plan->getKey(),
            'trial_days' => 14,
            'expires_in_hours' => 1,
        ], $platform->getKey());
        $this->travelTo($second['invitation']->expires_at->addHour());
        $expiredUrl = URL::temporarySignedRoute('onboarding-invitations.show', $second['invitation']->expires_at, [
            'invitation' => $second['invitation']->getKey(),
            'token' => $second['token'],
        ]);
        $this->get($expiredUrl)->assertForbidden();
        $this->assertSame(0, Artisan::call('onboarding:expire-invitations'));
        $this->assertSame(TenantOnboardingInvitationStatus::Expired, $second['invitation']->refresh()->status);
        $this->assertDatabaseCount('tenants', 1);
    }

    /** @return array<string, string|null> */
    private function acceptancePayload(): array
    {
        return [
            'company_name' => 'Atlas Invitation',
            'company_slug' => 'atlas-invitation',
            'legal_name' => 'Atlas Invitation SARL',
            'company_phone' => '+212500000000',
            'company_address' => 'Casablanca',
            'agency_code' => 'CASA',
            'agency_name' => 'Agence Casablanca',
            'agency_email' => 'casa@example.test',
            'agency_phone' => null,
            'agency_address' => 'Centre-ville',
            'owner_name' => 'Propriétaire Invité',
            'password' => 'MotDePasseSolide2026',
            'password_confirmation' => 'MotDePasseSolide2026',
        ];
    }

    private function plan(User $platform): SaasPlan
    {
        return app(CreateSaasPlan::class)->handle([
            'code' => 'onboarding-test',
            'name' => 'Accueil test',
            'description' => 'Plan du parcours d’accueil.',
            'billing_interval' => 'monthly',
            'price_amount' => '399.00',
            'currency' => 'MAD',
            'features' => ['Gestion de flotte'],
            'is_active' => true,
            'entitlements_configured' => true,
            'max_agencies' => 2,
            'max_users' => 5,
            'max_vehicles' => 20,
            'monthly_intelligence_runs' => 30,
            'intelligence_capabilities' => [
                IntelligenceCapability::DemandForecast->value,
                IntelligenceCapability::FleetReallocation->value,
            ],
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
