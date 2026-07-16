<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Ui\UiLabel;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Lot06EUxNavigationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_desktop_and_mobile_navigation_are_identical_and_filtered_for_six_tenant_roles(): void
    {
        $expected = [
            'tenant-owner' => ['dashboard', 'reservations', 'availability', 'contracts', 'pricing', 'customers', 'vehicles', 'vehicle-categories', 'maintenance', 'insurance', 'finance', 'reports', 'tenant', 'agencies', 'users', 'audit'],
            'agency-manager' => ['dashboard', 'reservations', 'availability', 'contracts', 'pricing', 'customers', 'vehicles', 'vehicle-categories', 'maintenance', 'insurance', 'finance', 'reports', 'agencies', 'users', 'audit'],
            'rental-agent' => ['dashboard', 'reservations', 'availability', 'contracts', 'pricing', 'customers', 'maintenance', 'insurance', 'finance', 'agencies', 'users'],
            'fleet-manager' => ['dashboard', 'reservations', 'availability', 'contracts', 'vehicles', 'vehicle-categories', 'maintenance', 'insurance', 'agencies'],
            'accountant' => ['dashboard', 'reservations', 'availability', 'contracts', 'pricing', 'finance', 'reports', 'agencies'],
            'viewer-auditor' => ['dashboard', 'reservations', 'availability', 'contracts', 'pricing', 'customers', 'vehicles', 'vehicle-categories', 'maintenance', 'insurance', 'finance', 'reports', 'agencies', 'users', 'audit'],
        ];
        $allTenantKeys = collect($expected)->flatten()->unique();

        foreach ($expected as $role => $allowedKeys) {
            $fixture = $this->fixture($role);
            $response = $this->actingAs($fixture['user'])->get(route('dashboard'))->assertOk();

            foreach ($allowedKeys as $key) {
                $this->assertSame(2, substr_count($response->getContent(), 'data-nav-key="'.$key.'"'), $role.' doit avoir '.$key.' sur desktop et mobile.');
            }
            foreach ($allTenantKeys->diff($allowedKeys) as $key) {
                $this->assertStringNotContainsString('data-nav-key="'.$key.'"', $response->getContent(), $role.' ne doit pas voir '.$key.'.');
            }
            $response->assertDontSee('data-nav-key="platform-dashboard"', false);
        }
    }

    public function test_platform_navigation_is_reserved_to_platform_administrator(): void
    {
        $platform = User::factory()->create(['tenant_id' => null, 'agency_id' => null, 'role_id' => null, 'is_platform_admin' => true]);
        $response = $this->actingAs($platform)->get(route('platform.dashboard'))->assertOk();

        $this->assertSame(2, substr_count($response->getContent(), 'data-nav-key="platform-dashboard"'));
        $this->assertSame(2, substr_count($response->getContent(), 'data-nav-key="platform-tenants"'));
        $response->assertDontSee('data-nav-key="reservations"', false);

        $tenantUser = $this->fixture('tenant-owner')['user'];
        $this->actingAs($tenantUser)->get(route('platform.dashboard'))->assertForbidden();
    }

    public function test_main_seeded_role_journeys_are_accessible_without_dead_navigation_links(): void
    {
        $journeys = [
            'tenant-owner' => ['tenant.show', 'agencies.index', 'users.index', 'reports.index'],
            'agency-manager' => ['vehicles.index', 'reservations.index', 'maintenance.index', 'reports.index'],
            'rental-agent' => ['customers.index', 'availability.index', 'reservations.index', 'contracts.index'],
            'fleet-manager' => ['vehicles.index', 'maintenance.index', 'insurance.index'],
            'accountant' => ['finance.index', 'reports.index', 'contracts.index'],
            'viewer-auditor' => ['audit-logs.index', 'vehicles.index', 'finance.index', 'reports.index'],
        ];

        foreach ($journeys as $role => $routes) {
            $user = $this->fixture($role)['user'];
            foreach ($routes as $route) {
                $this->actingAs($user)->get(route($route))->assertOk();
            }
        }
    }

    public function test_viewer_has_no_mutation_controls_and_forbidden_financial_route_stays_403(): void
    {
        $viewer = $this->fixture('viewer-auditor');
        $otherUser = User::factory()->create([
            'tenant_id' => $viewer['tenant']->id,
            'agency_id' => $viewer['agency']->id,
            'role_id' => Role::where('slug', 'rental-agent')->value('id'),
        ]);

        $this->actingAs($viewer['user'])->get(route('users.index'))
            ->assertOk()
            ->assertSee($otherUser->name)
            ->assertDontSee('Nouvel utilisateur')
            ->assertDontSee('Modifier');
        $this->actingAs($viewer['user'])->get(route('finance.index'))
            ->assertOk()
            ->assertDontSee('Créer une dépense')
            ->assertDontSee('Approuver');
        $this->actingAs($viewer['user'])->post(route('finance.expenses.store'), [
            'agency_id' => $viewer['agency']->id,
            'category' => 'administration',
            'description' => 'Interdit',
            'amount' => '10.00',
            'currency' => 'MAD',
            'expense_date' => today()->toDateString(),
        ])->assertForbidden();
    }

    public function test_agency_manager_lists_and_dashboard_are_limited_to_own_agency(): void
    {
        $fixture = $this->fixture('agency-manager');
        $otherAgency = app(TenantContext::class)->run($fixture['tenant'], fn () => Agency::factory()->create(['name' => 'Agence étrangère interne']));
        $ownActor = User::factory()->create(['tenant_id' => $fixture['tenant']->id, 'agency_id' => $fixture['agency']->id, 'role_id' => Role::where('slug', 'rental-agent')->value('id'), 'name' => 'Acteur Visible 06E']);
        $otherActor = User::factory()->create(['tenant_id' => $fixture['tenant']->id, 'agency_id' => $otherAgency->id, 'role_id' => Role::where('slug', 'rental-agent')->value('id'), 'name' => 'Acteur Invisible 06E']);

        AuditLog::withoutGlobalScopes()->create([
            'tenant_id' => $fixture['tenant']->id, 'agency_id' => $fixture['agency']->id, 'user_id' => $ownActor->id,
            'action' => 'agency.updated', 'auditable_type' => Agency::class, 'auditable_id' => $fixture['agency']->id,
            'old_values' => ['token' => 'NE-JAMAIS-AFFICHER-06E'], 'created_at' => now(),
        ]);
        AuditLog::withoutGlobalScopes()->create([
            'tenant_id' => $fixture['tenant']->id, 'agency_id' => $otherAgency->id, 'user_id' => $otherActor->id,
            'action' => 'agency.updated', 'auditable_type' => Agency::class, 'auditable_id' => $otherAgency->id, 'created_at' => now(),
        ]);

        $this->actingAs($fixture['user'])->get(route('agencies.index'))
            ->assertOk()->assertSee($fixture['agency']->name)->assertDontSee($otherAgency->name);
        $this->actingAs($fixture['user'])->get(route('users.index'))
            ->assertOk()->assertSee($ownActor->name)->assertDontSee($otherActor->name);
        $this->actingAs($fixture['user'])->get(route('dashboard'))
            ->assertOk()->assertSee($ownActor->name)->assertDontSee($otherActor->name)
            ->assertDontSee('NE-JAMAIS-AFFICHER-06E')->assertDontSee('DB_PASSWORD')
            ->assertDontSee('licence_number')->assertDontSee('identity_number');
    }

    public function test_dashboard_hides_finance_without_permission_and_required_password_still_blocks_it(): void
    {
        $fleet = $this->fixture('fleet-manager');
        $this->actingAs($fleet['user'])->get(route('dashboard'))
            ->assertOk()->assertDontSee('Factures impayées')->assertDontSee('Solde client à recevoir');

        $fleet['user']->forceFill(['must_change_password' => true])->save();
        $this->actingAs($fleet['user'])->get(route('dashboard'))->assertRedirect(route('password.change-required'));
    }

    public function test_profile_delete_is_absent_scope_fields_are_ignored_and_field_errors_are_visible(): void
    {
        $fixture = $this->fixture('tenant-owner');
        $original = $fixture['user']->only(['tenant_id', 'agency_id', 'role_id', 'is_active', 'is_platform_admin']);

        $this->actingAs($fixture['user'])->get(route('profile.edit'))
            ->assertOk()->assertDontSee('Supprimer le compte')->assertDontSee('Désactiver le compte');
        $this->actingAs($fixture['user'])->delete('/profile')->assertMethodNotAllowed();
        $this->actingAs($fixture['user'])->patch(route('profile.update'), [
            'name' => '', 'email' => 'adresse-invalide', 'tenant_id' => 999, 'agency_id' => 999,
            'role_id' => 999, 'is_active' => false, 'is_platform_admin' => true,
        ])->assertSessionHasErrors(['name', 'email']);
        $this->assertSame($original, $fixture['user']->refresh()->only(array_keys($original)));
    }

    public function test_french_labels_have_a_safe_unknown_fallback_and_filters_are_kept_in_pagination(): void
    {
        $this->assertSame('Confirmée', UiLabel::get('confirmed'));
        $this->assertSame('Responsable d’agence', UiLabel::get('agency-manager'));
        $this->assertSame('Virement bancaire', UiLabel::get('bank_transfer'));
        $this->assertSame('Valeur inconnue', UiLabel::get('future_status'));

        $fixture = $this->fixture('tenant-owner');
        $roleId = Role::where('slug', 'rental-agent')->value('id');
        foreach (range(1, 22) as $index) {
            User::factory()->create([
                'tenant_id' => $fixture['tenant']->id, 'agency_id' => $fixture['agency']->id, 'role_id' => $roleId,
                'name' => 'Utilisateur filtré '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            ]);
        }

        $this->actingAs($fixture['user'])->get(route('users.index', ['q' => 'Utilisateur filtré']))
            ->assertOk()->assertSee('22 résultat(s)')->assertSee('q=Utilisateur%20filtr%C3%A9', false);
    }

    private function fixture(string $roleSlug): array
    {
        $tenant = Tenant::factory()->create();
        $agency = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create());
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => $roleSlug === 'tenant-owner' ? null : $agency->id,
            'role_id' => $role->id,
            'must_change_password' => false,
        ]);

        return compact('tenant', 'agency', 'role', 'user');
    }
}
