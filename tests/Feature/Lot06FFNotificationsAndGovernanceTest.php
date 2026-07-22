<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Customer;
use App\Models\InternalNotification;
use App\Models\Permission;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\RoleAgencyDelegation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VehicleCategory;
use App\Support\Notifications\GenerateOperationalNotifications;
use App\Support\Tenancy\TenantContext;
use App\Support\Ui\UiLabel;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Lot06FFNotificationsAndGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_notification_inbox_is_isolated_by_tenant_agency_and_permission(): void
    {
        $a = $this->fixture('rental-agent');
        $b = $this->fixture('rental-agent');
        $otherAgency = $this->inTenant($a, fn () => Agency::factory()->create());
        $otherAgent = User::factory()->create(['tenant_id' => $a['tenant']->id, 'agency_id' => $otherAgency->id, 'role_id' => $a['role']->id, 'must_change_password' => false]);

        $visible = $this->notification($a, $a['user'], 'Alerte visible');
        $this->notification($b, $b['user'], 'Alerte autre entreprise');
        $this->notification([...$a, 'agency' => $otherAgency], $otherAgent, 'Alerte autre agence');

        $response = $this->actingAs($a['user'])->get(route('notifications.index'));
        $response->assertOk()->assertSee('Alerte visible')->assertDontSee('Alerte autre entreprise')->assertDontSee('Alerte autre agence');
        $response->assertSee('1</strong> non lue', false);

        $roleWithoutPermission = $this->customRole($a, 'Sans accès', []);
        $unauthorized = User::factory()->create(['tenant_id' => $a['tenant']->id, 'agency_id' => $a['agency']->id, 'role_id' => $roleWithoutPermission->id, 'must_change_password' => false]);
        DB::table('internal_notification_recipients')->insert(['tenant_id' => $a['tenant']->id, 'internal_notification_id' => $visible->id, 'user_id' => $unauthorized->id, 'created_at' => now()]);
        $this->actingAs($unauthorized)->get(route('notifications.index'))->assertOk()->assertDontSee('Alerte visible');
    }

    public function test_read_unread_and_read_all_are_user_scoped_and_audited(): void
    {
        $f = $this->fixture('rental-agent');
        $first = $this->notification($f, $f['user'], 'Première alerte');
        $second = $this->notification($f, $f['user'], 'Deuxième alerte');

        $this->actingAs($f['user'])->patch(route('notifications.read', $first))->assertRedirect();
        $this->assertNotNull(DB::table('internal_notification_recipients')->where('internal_notification_id', $first->id)->where('user_id', $f['user']->id)->value('read_at'));
        $this->actingAs($f['user'])->patch(route('notifications.unread', $first))->assertRedirect();
        $this->assertNull(DB::table('internal_notification_recipients')->where('internal_notification_id', $first->id)->where('user_id', $f['user']->id)->value('read_at'));
        $this->actingAs($f['user'])->post(route('notifications.read-all'))->assertRedirect();
        $this->assertSame(0, DB::table('internal_notification_recipients')->where('user_id', $f['user']->id)->whereNull('read_at')->count());
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $f['tenant']->id, 'action' => 'notification.all_read']);
        $this->assertNotNull($second->fresh());
    }

    public function test_notification_destination_is_server_controlled_and_marks_the_item_read(): void
    {
        $f = $this->fixture('rental-agent');
        $reservation = $this->reservation($f, 'pending');
        $notification = $this->notification($f, $f['user'], 'Réservation à traiter', $reservation);

        $this->actingAs($f['user'])->get(route('notifications.open', $notification))
            ->assertRedirect(route('reservations.show', $reservation));
        $this->assertNotNull(DB::table('internal_notification_recipients')->where('internal_notification_id', $notification->id)->where('user_id', $f['user']->id)->value('read_at'));

        $foreign = $this->fixture('rental-agent');
        $foreignReservation = $this->reservation($foreign, 'pending');
        $forged = $this->notification($f, $f['user'], 'Destination hors périmètre', $foreignReservation);
        $this->actingAs($f['user'])->get(route('notifications.open', $forged))->assertNotFound();
    }

    public function test_operational_generation_is_idempotent_covers_deadlines_and_contains_no_sensitive_data(): void
    {
        $f = $this->fixture('tenant-owner');
        $reservation = $this->reservation($f, 'pending', now()->addMinutes(30));
        $generator = app(GenerateOperationalNotifications::class);

        $first = $generator->handle();
        $count = InternalNotification::withoutGlobalScopes()->where('tenant_id', $f['tenant']->id)->count();
        $second = $generator->handle();

        $this->assertGreaterThan(0, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame($count, InternalNotification::withoutGlobalScopes()->where('tenant_id', $f['tenant']->id)->count());
        $alert = InternalNotification::withoutGlobalScopes()->where('deduplication_key', 'reservation:'.$reservation->id.':pending')->firstOrFail();
        $this->assertSame('urgent', $alert->priority);
        $content = mb_strtolower($alert->title.' '.$alert->summary);
        $this->assertStringNotContainsString(mb_strtolower($f['user']->email), $content);
        $this->assertStringNotContainsString('password', $content);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $f['tenant']->id, 'action' => 'notification.generated', 'auditable_id' => $alert->id]);
    }

    public function test_tenant_owner_creates_updates_and_deactivates_a_tenant_scoped_custom_role(): void
    {
        $f = $this->fixture('tenant-owner');
        $permissionIds = Permission::query()->whereIn('slug', ['customer.view', 'reservation.view'])->pluck('id')->all();

        $this->actingAs($f['user'])->post(route('roles.store'), ['name' => 'Accueil agence', 'permission_ids' => $permissionIds])
            ->assertRedirect(route('roles.index'));
        $role = Role::query()->where('tenant_id', $f['tenant']->id)->where('name', 'Accueil agence')->firstOrFail();
        $this->assertFalse($role->is_system);
        $this->assertSame($f['tenant']->id, $role->tenant_id);
        $this->assertEqualsCanonicalizing($permissionIds, $role->permissions()->pluck('permissions.id')->all());

        $replacement = Role::query()->where('slug', 'viewer-auditor')->firstOrFail();
        $assigned = User::factory()->create(['tenant_id' => $f['tenant']->id, 'agency_id' => $f['agency']->id, 'role_id' => $role->id]);
        $this->actingAs($f['user'])->put(route('roles.update', $role), ['name' => 'Accueil agence', 'permission_ids' => $permissionIds, 'is_active' => '0', 'replacement_role_id' => $replacement->id])->assertRedirect(route('roles.index'));
        $this->assertFalse($role->refresh()->is_active);
        $this->assertSame($replacement->id, $assigned->refresh()->role_id);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $f['tenant']->id, 'action' => 'role.assignments.replaced', 'auditable_id' => $role->id]);
    }

    public function test_custom_roles_reject_platform_permissions_duplicates_and_cross_tenant_access(): void
    {
        $a = $this->fixture('tenant-owner');
        $b = $this->fixture('tenant-owner');
        $platformPermission = Permission::query()->create(['slug' => 'platform.tenants.manage', 'name' => 'Administration plateforme', 'group' => 'platform']);

        $this->actingAs($a['user'])->post(route('roles.store'), ['name' => 'Interdit', 'permission_ids' => [$platformPermission->id]])->assertSessionHasErrors('permission_ids');
        $role = $this->customRole($a, 'Rôle unique', [Permission::where('slug', 'customer.view')->value('id')]);
        $this->actingAs($a['user'])->post(route('roles.store'), ['name' => 'rôle UNIQUE', 'permission_ids' => []])->assertSessionHasErrors('name');
        $this->actingAs($b['user'])->get(route('roles.edit', $role))->assertForbidden();
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'tenant_id' => $a['tenant']->id]);
    }

    public function test_agency_manager_can_assign_only_explicitly_delegated_roles_within_own_ceiling(): void
    {
        $f = $this->fixture('tenant-owner');
        $manager = $this->user($f, 'agency-manager');
        $rentalRole = Role::query()->where('slug', 'rental-agent')->firstOrFail();
        $accountantRole = Role::query()->where('slug', 'accountant')->firstOrFail();

        $this->actingAs($manager)->post(route('users.store'), $this->userPayload($f, $rentalRole, 'before@test.local'))->assertForbidden();
        $this->actingAs($f['user'])->put(route('roles.delegations.update', $f['agency']), ['role_ids' => [$rentalRole->id, $accountantRole->id]])->assertRedirect();
        $this->assertDatabaseHas('role_agency_delegations', ['tenant_id' => $f['tenant']->id, 'agency_id' => $f['agency']->id, 'role_id' => $rentalRole->id]);

        $this->actingAs($manager)->post(route('users.store'), $this->userPayload($f, $rentalRole, 'allowed@test.local'))->assertOk();
        $this->assertDatabaseHas('users', ['tenant_id' => $f['tenant']->id, 'agency_id' => $f['agency']->id, 'email' => 'allowed@test.local', 'role_id' => $rentalRole->id]);
        $this->actingAs($manager)->post(route('users.store'), $this->userPayload($f, $accountantRole, 'ceiling@test.local'))->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'ceiling@test.local']);
    }

    public function test_agency_manager_cannot_cross_agencies_self_escalate_or_manage_role_definitions(): void
    {
        $f = $this->fixture('tenant-owner');
        $manager = $this->user($f, 'agency-manager');
        $otherAgency = $this->inTenant($f, fn () => Agency::factory()->create());
        $rentalRole = Role::query()->where('slug', 'rental-agent')->firstOrFail();
        $this->inTenant($f, fn () => RoleAgencyDelegation::create(['agency_id' => $f['agency']->id, 'role_id' => $rentalRole->id, 'delegated_by' => $f['user']->id]));

        $payload = $this->userPayload($f, $rentalRole, 'cross@test.local');
        $payload['agency_id'] = $otherAgency->id;
        $payload['tenant_id'] = $f['tenant']->id;
        $this->actingAs($manager)->post(route('users.store'), $payload)->assertSessionHasErrors('tenant_id');
        unset($payload['tenant_id']);
        $this->actingAs($manager)->post(route('users.store'), $payload)->assertForbidden();
        $this->actingAs($manager)->put(route('users.update', $manager), ['name' => $manager->name, 'email' => $manager->email, 'role_id' => $rentalRole->id, 'agency_id' => $f['agency']->id, 'is_active' => '1'])->assertForbidden();
        $this->actingAs($manager)->get(route('roles.index'))->assertForbidden();
        $this->actingAs($manager)->post(route('roles.store'), ['name' => 'Escalade', 'permission_ids' => []])->assertForbidden();
    }

    public function test_system_roles_and_non_admin_roles_are_protected(): void
    {
        $owner = $this->fixture('tenant-owner');
        $system = Role::query()->where('slug', 'rental-agent')->firstOrFail();
        $this->actingAs($owner['user'])->get(route('roles.edit', $system))->assertForbidden();

        foreach (['agency-manager', 'rental-agent', 'fleet-manager', 'accountant', 'viewer-auditor'] as $slug) {
            $actor = $this->user($owner, $slug);
            $this->actingAs($actor)->get(route('roles.index'))->assertForbidden();
        }

        $platform = User::factory()->create(['tenant_id' => null, 'agency_id' => null, 'role_id' => null, 'is_platform_admin' => true]);
        $this->actingAs($platform)->get(route('roles.index'))->assertForbidden();
    }

    public function test_french_labels_validation_and_visible_pages_hide_technical_role_slugs(): void
    {
        $f = $this->fixture('tenant-owner');
        $this->assertSame('Responsable d’agence', UiLabel::get('agency-manager'));
        $this->assertSame('Retour à traiter', UiLabel::get('return_pending'));
        $this->assertSame('Avertissement', UiLabel::get('warning'));

        $this->actingAs($f['user'])->post(route('roles.store'), ['permission_ids' => []])->assertSessionHasErrors('name');
        $this->actingAs($f['user'])->get(route('roles.index'))->assertOk()->assertSee('Propriétaire du tenant')->assertDontSee('tenant-owner');
        $this->actingAs($f['user'])->get(route('notifications.index', ['priority' => 'invalid']))->assertSessionHasErrors('priority');
        $this->assertSame('fr', app()->getLocale());
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('rentfleet_test', DB::connection()->getDatabaseName());
    }

    private function fixture(string $roleSlug): array
    {
        $tenant = Tenant::factory()->create(['settings' => ['currency' => 'MAD', 'timezone' => 'Africa/Casablanca']]);
        $agency = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create());
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => $roleSlug === 'tenant-owner' ? null : $agency->id, 'role_id' => $role->id, 'must_change_password' => false]);

        return compact('tenant', 'agency', 'role', 'user');
    }

    private function user(array $fixture, string $roleSlug): User
    {
        return User::factory()->create(['tenant_id' => $fixture['tenant']->id, 'agency_id' => $roleSlug === 'tenant-owner' ? null : $fixture['agency']->id, 'role_id' => Role::where('slug', $roleSlug)->value('id'), 'must_change_password' => false]);
    }

    private function inTenant(array $fixture, callable $callback): mixed
    {
        return app(TenantContext::class)->run($fixture['tenant'], $callback, $fixture['agency']->id);
    }

    private function reservation(array $fixture, string $status, mixed $expiresAt = null): Reservation
    {
        return $this->inTenant($fixture, function () use ($fixture, $status, $expiresAt): Reservation {
            $category = VehicleCategory::create(['code' => 'F-'.str()->random(8), 'name' => 'Catégorie test', 'is_active' => true]);
            $customer = Customer::create(['agency_id' => $fixture['agency']->id, 'customer_type' => 'individual', 'first_name' => 'Client', 'last_name' => 'Notification', 'verification_status' => 'verified']);

            return Reservation::create(['agency_id' => $fixture['agency']->id, 'customer_id' => $customer->id, 'vehicle_category_id' => $category->id, 'reservation_number' => 'RES-F-'.str()->random(10), 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(3), 'status' => $status, 'subtotal' => '0.00', 'options_total' => '0.00', 'total_amount' => '0.00', 'deposit_amount' => '0.00', 'currency' => 'MAD', 'pricing_snapshot' => [], 'expires_at' => $expiresAt, 'created_by' => $fixture['user']->id]);
        });
    }

    private function notification(array $fixture, User $recipient, string $title, ?Reservation $resource = null): InternalNotification
    {
        $notification = $this->inTenant($fixture, fn () => InternalNotification::create(['agency_id' => $fixture['agency']->id, 'category' => 'reservation', 'priority' => 'information', 'title' => $title, 'summary' => 'Une action opérationnelle est attendue.', 'resource_type' => 'reservation', 'resource_id' => $resource?->id ?? 999999, 'required_permission' => 'reservation.view', 'deduplication_key' => 'test:'.str()->uuid(), 'occurred_at' => now()]));
        DB::table('internal_notification_recipients')->insert(['tenant_id' => $fixture['tenant']->id, 'internal_notification_id' => $notification->id, 'user_id' => $recipient->id, 'created_at' => now()]);

        return $notification;
    }

    private function customRole(array $fixture, string $name, array $permissions): Role
    {
        return $this->inTenant($fixture, function () use ($fixture, $name, $permissions): Role {
            $role = Role::forceCreate(['tenant_id' => $fixture['tenant']->id, 'name' => $name, 'slug' => str($name)->slug().'-'.str()->random(5), 'is_system' => false, 'is_active' => true, 'created_by' => $fixture['user']->id]);
            $role->permissions()->sync($permissions);

            return $role;
        });
    }

    private function userPayload(array $fixture, Role $role, string $email): array
    {
        return ['name' => 'Employé délégué', 'email' => $email, 'role_id' => $role->id, 'agency_id' => $fixture['agency']->id, 'is_active' => '1'];
    }
}
