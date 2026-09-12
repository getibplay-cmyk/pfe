<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = $this->createTenantOwner();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = $this->createTenantOwner();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'current_password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = $this->createTenantOwner();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_changing_email_requires_the_current_password_and_never_flashes_it(): void
    {
        $user = $this->createTenantOwner();
        $email = $user->email;
        foreach ([null, 'incorrect-private-password'] as $password) {
            $this->actingAs($user)->patch(route('profile.update'), [
                'name' => $user->name, 'email' => 'replacement@example.com', 'current_password' => $password,
            ])->assertSessionHasErrors('current_password');
            $this->assertSame($email, $user->fresh()->email);
            $this->assertNull(session()->getOldInput('current_password'));
            $this->assertNotNull($user->fresh()->email_verified_at);
        }
    }

    public function test_name_only_change_does_not_require_or_persist_a_password(): void
    {
        $user = $this->createTenantOwner();
        $passwordHash = $user->password;
        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => 'Nouveau nom', 'email' => $user->email, 'current_password' => 'not-needed', 'password' => 'not-allowed',
        ])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));
        $this->assertSame($passwordHash, $user->fresh()->password);
        $this->assertSame('Nouveau nom', $user->fresh()->name);
    }

    public function test_profile_cannot_change_tenant_role_agency_or_status(): void
    {
        $user = $this->createTenantOwner(['is_active' => true]);
        $original = $user->only(['tenant_id', 'agency_id', 'role_id', 'is_active']);

        $this->actingAs($user)->patch('/profile', [
            'name' => 'Nom autorisé',
            'email' => $user->email,
            'tenant_id' => 999,
            'agency_id' => 999,
            'role_id' => 999,
            'is_active' => false,
            'is_platform_admin' => true,
        ])->assertRedirect('/profile');

        $this->assertSame($original, $user->refresh()->only(array_keys($original)));
        $this->assertFalse($user->is_platform_admin);
    }

    public function test_profile_deletion_route_is_absent(): void
    {
        $user = $this->createTenantOwner();

        $this->actingAs($user)->delete('/profile')->assertMethodNotAllowed();
        $this->actingAs($user)->get('/profile')->assertOk()->assertDontSee('Supprimer le compte')->assertDontSee('Désactiver le compte');
    }
}
