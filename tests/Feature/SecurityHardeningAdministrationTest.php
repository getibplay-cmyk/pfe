<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHardeningAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_stale_confirmation_cannot_suspend_a_tenant_and_fresh_confirmation_can(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => null, 'agency_id' => null, 'role_id' => null, 'is_platform_admin' => true]);
        $payload = ['reason' => 'Contrôle de sécurité synthétique'];
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->subMinutes(15)->timestamp])
            ->postJson(route('platform.tenants.suspend', $tenant), $payload)->assertStatus(423);
        $this->assertSame('active', $tenant->fresh()->status->value);
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->post(route('platform.tenants.suspend', $tenant), $payload)->assertRedirect();
        $this->assertSame('suspended', $tenant->fresh()->status->value);
    }

    public function test_administrative_mutations_require_confirmation_even_with_valid_permissions(): void
    {
        $owner = $this->createTenantOwner();
        foreach (['users.store', 'roles.store', 'agencies.store', 'booking-admin.settings.save'] as $name) {
            $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => null])
                ->postJson(route($name), [])->assertStatus(423);
        }
        $this->withSession(['auth.password_confirmed_at' => null])->patchJson(route('tenant.update'), [])->assertStatus(423);
    }

    public function test_another_tenant_cannot_be_modified_after_successful_confirmation(): void
    {
        $owner = $this->createTenantOwner();
        $other = $this->createTenantOwner();
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->putJson(route('users.update', $other), ['name' => 'Intrusion fictive'])->assertForbidden();
        $this->assertNotSame('Intrusion fictive', $other->fresh()->name);
    }

    public function test_confirmation_rejects_non_string_passwords_and_rotates_the_session(): void
    {
        $owner = $this->createTenantOwner();
        $this->actingAs($owner)->postJson(route('password.confirm.store'), ['password' => ['invalid']])->assertUnprocessable();
        $before = session()->getId();
        $this->post(route('password.confirm.store'), ['password' => 'password'])->assertRedirect();
        $this->assertNotSame($before, session()->getId());
        $this->assertIsInt(session('auth.password_confirmed_at'));
    }
}
