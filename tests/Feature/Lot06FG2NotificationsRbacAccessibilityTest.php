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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Lot06FG2NotificationsRbacAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_operational_incident_is_updated_resolved_and_reactivated_without_duplicate(): void
    {
        $fixture = $this->fixture();
        $reservation = $this->reservation($fixture, now()->addMinutes(30));
        $generator = app(GenerateOperationalNotifications::class);

        $first = $generator->handle();
        $notification = InternalNotification::withoutGlobalScopes()
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('deduplication_key', 'reservation:'.$reservation->id.':pending')
            ->firstOrFail();
        $createdAt = $notification->created_at;

        $this->assertSame(1, $first['created']);
        $this->assertSame('urgent', $notification->priority);
        $this->assertSame(1, $notification->occurrence_count);
        $this->assertNotNull($notification->due_at);

        $unchanged = $generator->handle();
        $notification->refresh();

        $this->assertSame(0, $unchanged['created']);
        $this->assertSame(0, $unchanged['updated']);
        $this->assertSame(0, $unchanged['resolved']);
        $this->assertSame(0, $unchanged['reactivated']);
        $this->assertTrue($createdAt->equalTo($notification->created_at));
        $this->assertSame(1, $notification->occurrence_count);

        $this->inTenant($fixture, fn () => $reservation->forceFill([
            'expires_at' => now()->addDays(2),
        ])->save());
        $second = $generator->handle();
        $notification->refresh();

        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['updated']);
        $this->assertSame('information', $notification->priority);
        $this->assertTrue($createdAt->equalTo($notification->created_at));
        $this->assertSame(1, $notification->occurrence_count);

        $this->actingAs($fixture['owner'])->patch(route('notifications.read', $notification))->assertRedirect();
        $this->inTenant($fixture, fn () => $reservation->forceFill(['status' => 'confirmed'])->save());
        $resolved = $generator->handle();
        $notification->refresh();

        $this->assertSame(1, $resolved['resolved']);
        $this->assertNotNull($notification->resolved_at);
        $this->assertSame('cause_disparue', $notification->resolution_reason);
        $this->actingAs($fixture['owner'])->get(route('notifications.index'))->assertDontSee($notification->title);
        $this->get(route('notifications.index', ['status' => 'resolved']))->assertSee($notification->title);

        $this->inTenant($fixture, fn () => $reservation->forceFill([
            'status' => 'pending',
            'expires_at' => now()->addMinutes(20),
        ])->save());
        $reactivated = $generator->handle();
        $notification->refresh();

        $this->assertSame(1, $reactivated['reactivated']);
        $this->assertNull($notification->resolved_at);
        $this->assertSame(2, $notification->occurrence_count);
        $this->assertNull(DB::table('internal_notification_recipients')
            ->where('internal_notification_id', $notification->id)
            ->where('user_id', $fixture['owner']->id)
            ->value('read_at'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'notification.resolved', 'auditable_id' => $notification->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'notification.reactivated', 'auditable_id' => $notification->id]);
        $this->assertSame(1, InternalNotification::withoutGlobalScopes()
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('deduplication_key', $notification->deduplication_key)
            ->count());
    }

    public function test_postgresql_rejects_incoherent_user_role_and_delegation_assignments(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $customA = $this->customRole($a, 'Accueil A', ['reservation.view']);
        $customB = $this->customRole($b, 'Accueil B', ['reservation.view']);
        $inactive = $this->customRole($a, 'Inactif', []);
        $inactive->forceFill(['is_active' => false])->save();
        $platformRole = Role::query()->forceCreate([
            'tenant_id' => null,
            'name' => 'Administrateur de la plateforme',
            'slug' => 'platform-admin',
            'is_system' => true,
            'is_active' => true,
        ]);

        $this->expectConstraint(fn () => DB::table('users')->where('id', $a['agent']->id)->update(['role_id' => $customB->id]));
        $this->expectConstraint(fn () => DB::table('users')->where('id', $a['agent']->id)->update(['role_id' => $inactive->id]));
        $this->expectConstraint(fn () => DB::table('users')->where('id', $a['agent']->id)->update(['role_id' => $platformRole->id]));
        $this->expectConstraint(fn () => DB::table('users')->where('id', $a['agent']->id)->update(['agency_id' => null]));
        $this->expectConstraint(fn () => DB::table('users')->where('id', $a['agent']->id)->update([
            'is_platform_admin' => true,
            'tenant_id' => null,
            'agency_id' => null,
            'role_id' => $customA->id,
        ]));
        $this->expectConstraint(fn () => DB::table('role_agency_delegations')->insert([
            'tenant_id' => $a['tenant']->id,
            'agency_id' => $a['agency']->id,
            'role_id' => $customA->id,
            'delegated_by' => $b['owner']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $assignedRole = $this->customRole($a, 'Encore attribué', []);
        $assigned = User::factory()->create([
            'tenant_id' => $a['tenant']->id,
            'agency_id' => $a['agency']->id,
            'role_id' => $assignedRole->id,
        ]);
        $this->expectConstraint(fn () => DB::table('roles')->where('id', $assignedRole->id)->update(['is_active' => false]));
        $this->assertSame($assignedRole->id, $assigned->refresh()->role_id);
    }

    public function test_role_replacement_is_filtered_confirmed_non_escalating_and_atomic(): void
    {
        $fixture = $this->fixture();
        $secondAgency = $this->inTenant($fixture, fn () => Agency::factory()->create());
        $source = $this->customRole($fixture, 'Accueil étendu', ['reservation.view', 'reservation.create']);
        $safe = $this->customRole($fixture, 'Accueil lecture', ['reservation.view']);
        $escalating = $this->customRole($fixture, 'Accueil supérieur', ['reservation.view', 'reservation.create', 'reservation.confirm']);
        $notDelegated = $this->customRole($fixture, 'Accueil non délégué', ['reservation.view']);
        $foreign = $this->customRole($this->fixture(), 'Accueil étranger', ['reservation.view']);
        $inactive = $this->customRole($fixture, 'Accueil désactivé', ['reservation.view']);
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$fixture['agency'], $secondAgency] as $agency) {
            $this->inTenant($fixture, fn () => RoleAgencyDelegation::create([
                'agency_id' => $agency->id,
                'role_id' => $safe->id,
                'delegated_by' => $fixture['owner']->id,
            ]));
            $this->inTenant($fixture, fn () => RoleAgencyDelegation::create([
                'agency_id' => $agency->id,
                'role_id' => $escalating->id,
                'delegated_by' => $fixture['owner']->id,
            ]));
        }

        $users = collect([
            User::factory()->create(['tenant_id' => $fixture['tenant']->id, 'agency_id' => $fixture['agency']->id, 'role_id' => $source->id]),
            User::factory()->create(['tenant_id' => $fixture['tenant']->id, 'agency_id' => $secondAgency->id, 'role_id' => $source->id]),
        ]);

        $page = $this->actingAs($fixture['owner'])->get(route('roles.edit', $source));
        $page->assertOk()->assertSee($safe->name)->assertSee('2 utilisateurs seront réaffectés.')
            ->assertDontSee($escalating->name)->assertDontSee($notDelegated->name)
            ->assertDontSee($foreign->name)->assertDontSee($inactive->name)
            ->assertDontSee('value="'.Role::query()->where('slug', 'tenant-owner')->value('id').'"', false);

        $payload = [
            'name' => $source->name,
            'permission_ids' => $source->permissions()->pluck('permissions.id')->all(),
            'is_active' => '0',
            'replacement_role_id' => $safe->id,
        ];
        $this->put(route('roles.update', $source), $payload)->assertSessionHasErrors('confirm_replacement');
        $this->assertTrue($source->refresh()->is_active);
        $this->assertTrue($users->every(fn (User $user): bool => $user->refresh()->role_id === $source->id));

        $payload['replacement_role_id'] = $escalating->id;
        $payload['confirm_replacement'] = '1';
        $this->put(route('roles.update', $source), $payload)->assertSessionHasErrors('replacement_role_id');
        $this->assertTrue($source->refresh()->is_active);
        $this->assertTrue($users->every(fn (User $user): bool => $user->refresh()->role_id === $source->id));

        $payload['replacement_role_id'] = $safe->id;
        $this->put(route('roles.update', $source), $payload)->assertRedirect(route('roles.index'));
        $this->assertFalse($source->refresh()->is_active);
        $this->assertTrue($users->every(fn (User $user): bool => $user->refresh()->role_id === $safe->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.replacement.requested', 'auditable_id' => $source->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.assignments.replaced', 'auditable_id' => $source->id]);
    }

    public function test_every_known_permission_has_a_central_french_label(): void
    {
        $permissions = Permission::query()->orderBy('slug')->get();

        $this->assertNotEmpty($permissions);
        $permissions->each(function (Permission $permission): void {
            $label = UiLabel::permission($permission->slug);
            $this->assertNotSame('Permission non documentée', $label, $permission->slug);
            $this->assertNotSame($permission->slug, $label);
            $this->assertDoesNotMatchRegularExpression('/\b(view|manage|create|update|tenant|owner|manager)\b/i', $label);
        });

        $this->assertSame('Administrateur de l’entreprise', UiLabel::get('tenant-owner'));
        $this->assertSame('Responsable d’agence', UiLabel::get('agency-manager'));
        $this->assertSame('Administrateur de la plateforme', UiLabel::get('platform-admin'));
    }

    public function test_priority_governance_forms_have_named_controls_errors_and_confirmation_context(): void
    {
        $fixture = $this->fixture();
        $role = $this->customRole($fixture, 'Rôle accessible', ['reservation.view']);

        $rolePage = $this->actingAs($fixture['owner'])->get(route('roles.edit', $role));
        $rolePage->assertOk()
            ->assertSee('for="role-name"', false)
            ->assertSee('id="replacement-role"', false)
            ->assertSee('id="replacement-help"', false)
            ->assertSee('aria-describedby="replacement-help"', false);

        $platform = User::factory()->create([
            'tenant_id' => null,
            'agency_id' => null,
            'role_id' => null,
            'is_platform_admin' => true,
        ]);
        $platformPage = $this->actingAs($platform)->get(route('platform.tenants.index'));
        $platformPage->assertOk()
            ->assertSee('for="platform-tenant-search"', false)
            ->assertSee('for="platform-tenant-status"', false)
            ->assertSee('role="region"', false)
            ->assertSee('tabindex="0"', false)
            ->assertDontSee('Nouveau tenant')
            ->assertSee('Nouvelle entreprise cliente');

        $priorityForms = [
            resource_path('views/drivers/show.blade.php') => ['for="driver-rejection-reason"', 'id="driver-rejection-error"'],
            resource_path('views/contracts/show.blade.php') => ['for="departure-mileage"', 'for="return-mileage"', '<legend'],
            resource_path('views/finance/show.blade.php') => ['for="invoice-due-at"', 'for="invoice-void-reason"'],
            resource_path('views/insurance/claims/show.blade.php') => ['for="claim-review-note"', 'for="claim-rejection-note"', 'for="claim-settled-amount"'],
            resource_path('views/insurance/policies/show.blade.php') => ['for="coverage-type"', 'for="coverage-limit"', 'for="coverage-deductible"'],
            resource_path('views/insurance/coverages/edit.blade.php') => ['for="coverage-edit-type"', 'for="coverage-edit-limit"', 'for="coverage-edit-deductible"'],
            resource_path('views/notifications/index.blade.php') => ['for="notification-status"', 'aria-live="polite"'],
            resource_path('views/roles/delegations.blade.php') => ['<fieldset', '<legend'],
            resource_path('views/users/form.blade.php') => ['for="user-role"', 'for="user-agency"'],
        ];

        foreach ($priorityForms as $path => $markers) {
            $contents = file_get_contents($path);
            $this->assertIsString($contents, $path);
            foreach ($markers as $marker) {
                $this->assertStringContainsString($marker, $contents, $path);
            }
        }
    }

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create();
        $agency = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create());
        $owner = User::factory()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => null,
            'role_id' => Role::query()->where('slug', 'tenant-owner')->value('id'),
            'must_change_password' => false,
        ]);
        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'role_id' => Role::query()->where('slug', 'rental-agent')->value('id'),
            'must_change_password' => false,
        ]);

        return compact('tenant', 'agency', 'owner', 'agent');
    }

    private function reservation(array $fixture, mixed $expiresAt): Reservation
    {
        return $this->inTenant($fixture, function () use ($fixture, $expiresAt): Reservation {
            $category = VehicleCategory::create([
                'code' => 'G2-'.str()->random(8),
                'name' => 'Catégorie G2',
                'is_active' => true,
            ]);
            $customer = Customer::create([
                'agency_id' => $fixture['agency']->id,
                'customer_type' => 'individual',
                'first_name' => 'Client',
                'last_name' => 'G2',
                'verification_status' => 'verified',
            ]);

            return Reservation::create([
                'agency_id' => $fixture['agency']->id,
                'customer_id' => $customer->id,
                'vehicle_category_id' => $category->id,
                'reservation_number' => 'RES-G2-'.str()->random(8),
                'starts_at' => now()->addDays(2),
                'ends_at' => now()->addDays(3),
                'status' => 'pending',
                'subtotal' => '0.00',
                'options_total' => '0.00',
                'total_amount' => '0.00',
                'deposit_amount' => '0.00',
                'currency' => 'MAD',
                'pricing_snapshot' => [],
                'expires_at' => $expiresAt,
                'created_by' => $fixture['owner']->id,
            ]);
        });
    }

    private function customRole(array $fixture, string $name, array $permissionSlugs): Role
    {
        return $this->inTenant($fixture, function () use ($fixture, $name, $permissionSlugs): Role {
            $role = Role::query()->forceCreate([
                'tenant_id' => $fixture['tenant']->id,
                'name' => $name,
                'slug' => str($name)->slug().'-'.str()->random(6),
                'is_system' => false,
                'is_active' => true,
                'created_by' => $fixture['owner']->id,
            ]);
            $role->permissions()->sync(Permission::query()->whereIn('slug', $permissionSlugs)->pluck('id'));

            return $role;
        });
    }

    private function inTenant(array $fixture, callable $callback): mixed
    {
        return app(TenantContext::class)->run($fixture['tenant'], $callback, $fixture['agency']->id);
    }

    private function expectConstraint(callable $callback): void
    {
        DB::beginTransaction();

        try {
            $callback();
            DB::rollBack();
            $this->fail('Une contrainte PostgreSQL aurait dû refuser la mutation.');
        } catch (QueryException $exception) {
            DB::rollBack();
            $this->assertSame('23514', $exception->getCode());
        }
    }
}
