<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DemoTenancySeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class MultitenancyIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_user_cannot_read_another_tenant_or_select_it_from_request(): void
    {
        [$tenant, $agency, $owner] = $this->tenantUser('tenant-owner');
        $other = Tenant::factory()->create();

        Gate::forUser($owner)->authorize('view', $tenant);
        $this->assertTrue(Gate::forUser($owner)->denies('view', $other));
        $this->actingAs($owner)->get('/tenant?tenant_id='.$other->id)
            ->assertOk()
            ->assertSee($tenant->name)
            ->assertDontSee($other->name);
    }

    public function test_foreign_agency_cannot_be_modified_deleted_or_bound(): void
    {
        [, , $owner] = $this->tenantUser('tenant-owner');
        [$otherTenant, $foreignAgency] = $this->tenantUser('tenant-owner');

        $payload = ['code' => 'HACK', 'name' => 'Interdit', 'is_active' => '1'];
        $this->actingAs($owner)->get(route('agencies.edit', $foreignAgency))->assertNotFound();
        $this->actingAs($owner)->put(route('agencies.update', $foreignAgency), $payload)->assertNotFound();
        $this->actingAs($owner)->delete(route('agencies.destroy', $foreignAgency))->assertNotFound();

        $this->assertDatabaseHas('agencies', ['id' => $foreignAgency->id, 'tenant_id' => $otherTenant->id]);
    }

    public function test_tenant_id_injected_when_creating_agency_is_rejected(): void
    {
        [, , $owner] = $this->tenantUser('tenant-owner');
        $other = Tenant::factory()->create();

        $this->actingAs($owner)->post(route('agencies.store'), [
            'tenant_id' => $other->id,
            'code' => 'INJECT',
            'name' => 'Injection',
            'is_active' => '1',
        ])->assertSessionHasErrors('tenant_id');

        $this->assertDatabaseMissing('agencies', ['code' => 'INJECT']);
    }

    public function test_inactive_user_cannot_login(): void
    {
        [, , $user] = $this->tenantUser('tenant-owner', isActive: false);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertGuest();
    }

    public function test_tenant_owner_sees_all_tenant_agencies(): void
    {
        [$tenant, $firstAgency, $owner] = $this->tenantUser('tenant-owner');
        $secondAgency = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create());

        $this->actingAs($owner)->get(route('agencies.index'))
            ->assertOk()
            ->assertSee($firstAgency->name)
            ->assertSee($secondAgency->name);
    }

    public function test_agency_manager_is_limited_to_assigned_agency_and_users(): void
    {
        [$tenant, $assignedAgency, $manager] = $this->tenantUser('agency-manager');
        $otherAgency = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create());
        User::factory()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => $otherAgency->id,
            'role_id' => Role::where('slug', 'rental-agent')->value('id'),
        ]);

        $this->actingAs($manager)->get(route('agencies.index'))
            ->assertOk()
            ->assertSee($assignedAgency->name)
            ->assertDontSee($otherAgency->name);
        $this->actingAs($manager)->get(route('users.index'))->assertOk()->assertDontSee($otherAgency->name);
    }

    public function test_platform_admin_uses_only_dedicated_routes(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true, 'tenant_id' => null]);

        $this->actingAs($admin)->get(route('platform.dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('dashboard'))->assertForbidden();
        $this->actingAs($admin)->get(route('agencies.index'))->assertForbidden();
    }

    public function test_agency_update_creates_tenant_scoped_audit_log(): void
    {
        [$tenant, $agency, $owner] = $this->tenantUser('tenant-owner');

        $this->actingAs($owner)->put(route('agencies.update', $agency), [
            'code' => $agency->code,
            'name' => 'Agence mise à jour',
            'is_active' => '1',
        ])->assertRedirect(route('agencies.index'));

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'action' => 'agency.updated',
            'auditable_id' => $agency->id,
        ]);
    }

    public function test_user_audit_never_contains_password(): void
    {
        [$tenant, $agency, $owner] = $this->tenantUser('tenant-owner');
        $role = Role::where('slug', 'rental-agent')->firstOrFail();

        $this->actingAs($owner)->post(route('users.store'), [
            'tenant_id' => $tenant->id,
            'name' => 'Tentative injectée',
            'email' => 'blocked@example.test',
            'password' => 'VerySecret123!',
            'role_id' => $role->id,
            'agency_id' => $agency->id,
            'is_active' => '1',
        ])->assertSessionHasErrors('tenant_id');

        $this->actingAs($owner)->post(route('users.store'), [
            'name' => 'Agent Démo',
            'email' => 'agent@example.test',
            'role_id' => $role->id,
            'agency_id' => $agency->id,
            'is_active' => '1',
        ])->assertOk()->assertViewIs('shared.temporary-password');

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'user.created')->firstOrFail();
        $encoded = json_encode([$audit->old_values, $audit->new_values]);
        $this->assertStringNotContainsString('password', strtolower($encoded));
        $this->assertStringNotContainsString('VerySecret123!', $encoded);
    }

    public function test_suite_still_uses_dedicated_postgresql_database(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('rentfleet_test', DB::connection()->getDatabaseName());
    }

    public function test_demo_seeder_creates_two_tenants_three_agencies_and_users_for_roles(): void
    {
        $this->seed(DemoTenancySeeder::class);

        $this->assertDatabaseCount('tenants', 2);
        $this->assertDatabaseCount('agencies', 3);
        $this->assertSame(6, Role::whereNull('tenant_id')->count());
        $this->assertSame(9, User::count());
    }

    private function tenantUser(string $roleSlug, bool $isActive = true): array
    {
        $tenant = Tenant::factory()->create();
        $agency = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create());
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => $roleSlug === 'tenant-owner' ? null : $agency->id,
            'role_id' => $role->id,
            'is_active' => $isActive,
        ]);

        return [$tenant, $agency, $user];
    }
}
