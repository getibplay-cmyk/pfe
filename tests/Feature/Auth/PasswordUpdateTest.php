<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated(): void
    {
        $user = $this->createTenantOwner();
        DB::table('sessions')->insert([
            'id' => 'another-session-for-password-test',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'NewPassword2026',
                'password_confirmation' => 'NewPassword2026',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertTrue(Hash::check('NewPassword2026', $user->refresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'another-session-for-password-test']);
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = $this->createTenantOwner();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'NewPassword2026',
                'password_confirmation' => 'NewPassword2026',
            ]);

        $response
            ->assertSessionHasErrorsIn('updatePassword', 'current_password')
            ->assertRedirect('/profile');
    }

    public function test_password_change_invalidates_remembered_access_and_rotates_the_current_session(): void
    {
        $user = $this->createTenantOwner(['remember_token' => 'old-remember-token']);
        $oldToken = $user->remember_token;
        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
        $oldSession = session()->getId();
        $provider = Auth::guard('web')->getProvider();
        $this->assertNotNull($provider->retrieveByToken($user->id, $oldToken));
        $this->put(route('password.update'), [
            'current_password' => 'password', 'password' => 'UpdatedPassword2026!', 'password_confirmation' => 'UpdatedPassword2026!',
        ])->assertSessionHasNoErrors();
        $this->assertNull($provider->retrieveByToken($user->id, $oldToken));
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertSame(1, $user->fresh()->security_version);
        $this->assertAuthenticatedAs($user);
    }
}
