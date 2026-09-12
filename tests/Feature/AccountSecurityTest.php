<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Auth\AccountSecurity;
use App\Support\Auth\Totp;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00:00 UTC'));
    }

    public function test_activation_requires_password_confirmation_and_a_valid_code(): void
    {
        $user = $this->createTenantOwner();
        $this->actingAs($user)->post(route('security.prepare'))->assertRedirect(route('password.confirm'));
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->post(route('security.prepare'))->assertRedirect();
        $secret = $user->fresh()->mfa_pending_secret;
        $this->assertNotSame($secret, DB::table('users')->where('id', $user->id)->value('mfa_pending_secret'));
        $this->post(route('security.enroll'), ['code' => 'invalid'])->assertSessionHasErrors('code');
        $response = $this->post(route('security.enroll'), ['code' => app(Totp::class)->code($secret, intdiv(now()->timestamp, 30))])->assertOk();
        $this->assertCount(10, $response->viewData('codes'));
        $this->assertNotNull($user->fresh()->mfa_confirmed_at);
        $this->assertArrayNotHasKey('mfa_secret', $user->fresh()->toArray());
        $this->assertNull(session()->getOldInput('code'));
        $this->actingAs($user->fresh())->get(route('profile.edit'))->assertOk();
        $response->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringNotContainsString($secret, AuditLog::withoutGlobalScopes()->get()->toJson());
    }

    public function test_mfa_blocks_business_profile_and_platform_before_access(): void
    {
        $user = $this->enabled();
        $this->actingAs($user)->get(route('profile.edit'))->assertRedirect(route('security.challenge'));
        $this->getJson(route('dashboard'))->assertForbidden();
        $this->get(route('security.challenge'))->assertOk();
        $platform = User::factory()->create(['is_platform_admin' => true, 'tenant_id' => null]);
        $platform->forceFill(['mfa_secret' => app(Totp::class)->secret(), 'mfa_confirmed_at' => now(), 'security_version' => 1])->save();
        $this->actingAs($platform)->get(route('platform.dashboard'))->assertRedirect(route('security.challenge'));
    }

    public function test_proof_is_bound_to_user_and_security_version(): void
    {
        $user = $this->enabled();
        $this->actingAs($user)->withSession(['mfa_verified' => ['user_id' => $user->id + 1, 'version' => $user->security_version]])
            ->get(route('profile.edit'))->assertRedirect(route('security.challenge'));
        $this->withSession(['mfa_verified' => ['user_id' => $user->id, 'version' => $user->security_version - 1]])
            ->get(route('profile.edit'))->assertRedirect(route('security.challenge'));
    }

    public function test_totp_and_recovery_codes_are_single_use(): void
    {
        $user = $this->enabled();
        $code = app(Totp::class)->code($user->mfa_secret, intdiv(now()->timestamp, 30));
        $this->actingAs($user)->post(route('security.verify'), ['code' => $code])->assertRedirect();
        $this->post(route('security.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->post(route('security.verify'), ['code' => '1234-5678-90ab-cdef'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('security.verify'), ['code' => '1234-5678-90ab-cdef'])->assertSessionHasErrors('code');
        $this->assertSame([], $user->fresh()->mfa_recovery_hashes);
    }

    public function test_expired_pending_activation_does_not_enable_mfa(): void
    {
        $user = $this->createTenantOwner();
        $secret = app(Totp::class)->secret();
        $user->forceFill(['mfa_pending_secret' => $secret, 'mfa_pending_at' => now()->subMinutes(11)])->save();
        $this->expectException(ValidationException::class);
        app(AccountSecurity::class)->enroll($user, app(Totp::class)->code($secret, intdiv(now()->timestamp, 30)));
    }

    public function test_revocation_cannot_delete_another_users_session(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->createTenantOwner();
        $other = $this->createTenantOwner();
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $other->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->delete(route('security.revoke'), ['session_key' => Crypt::encryptString('other-device')])->assertNotFound();
        $this->assertDatabaseHas('sessions', ['id' => 'other-device']);
        DB::table('sessions')->insert(['id' => 'own-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        $this->delete(route('security.revoke'), ['session_key' => Crypt::encryptString('own-device')])->assertRedirect();
        $this->assertDatabaseMissing('sessions', ['id' => 'own-device']);
    }

    public function test_admin_requirement_is_enforceable_without_blocking_enrollment(): void
    {
        config(['security.mfa_require_admins' => true]);
        $user = $this->createTenantOwner();
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('security.index'));
        $this->get(route('security.index'))->assertOk();
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->post(route('security.prepare'))->assertRedirect(route('security.index'));
    }

    private function enabled(): User
    {
        $user = $this->createTenantOwner();
        $user->forceFill([
            'mfa_secret' => app(Totp::class)->secret(), 'mfa_confirmed_at' => now(),
            'security_version' => 1, 'mfa_recovery_hashes' => [hash('sha256', '1234-5678-90ab-cdef')],
        ])->save();

        return $user;
    }
}
