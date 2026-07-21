<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Ui\UiLabel;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class Lot06FE1ProfessionalUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_login_is_a_french_b2b_experience_without_public_registration(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Connexion à RentFleet')
            ->assertSee('SaaS B2B multitenant')
            ->assertSee('inscription publique est désactivée')
            ->assertSee('Afficher le mot de passe')
            ->assertSee('Se souvenir de moi')
            ->assertSee('Mot de passe oublié')
            ->assertSee('autocomplete="username"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertDontSee('Sign up')
            ->assertDontSee('Register');

        $this->get('/register')->assertNotFound();
        $this->get('/signup')->assertNotFound();
        $this->get(route('password.request'))->assertOk()->assertSee('Mot de passe oublié');
    }

    public function test_authentication_errors_have_a_summary_and_field_feedback(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag([
            'email' => ['Ces identifiants ne correspondent pas à nos enregistrements.'],
        ]));
        view()->share('errors', $errors);
        $summary = Blade::render('<x-form-errors />');
        view()->share('errors', new ViewErrorBag);

        $this->assertStringContainsString('Le formulaire contient', $summary);
        $this->assertStringContainsString('role="alert"', $summary);
        $this->assertStringContainsString('aria-live="assertive"', $summary);
        $this->get(route('login'))->assertOk()->assertSee('email-error', false);
    }

    public function test_required_password_change_and_profile_are_complete_without_self_deactivation(): void
    {
        $fixture = $this->fixture('tenant-owner');
        $fixture['user']->forceFill(['must_change_password' => true])->save();

        $this->actingAs($fixture['user'])->get(route('password.change-required'))
            ->assertOk()
            ->assertSee('Choisissez votre mot de passe')
            ->assertSee('Mot de passe temporaire')
            ->assertSee('Afficher le mot de passe');

        $fixture['user']->forceFill(['must_change_password' => false])->save();
        $this->actingAs($fixture['user'])->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Informations personnelles')
            ->assertSee('Rattachement')
            ->assertSee('Sécurité du compte')
            ->assertDontSee('Supprimer le compte')
            ->assertDontSee('Désactiver le compte');
        $this->actingAs($fixture['user'])->delete('/profile')->assertMethodNotAllowed();
    }

    public function test_navigation_is_role_filtered_active_and_identical_on_desktop_and_mobile(): void
    {
        $expected = [
            'tenant-owner' => ['dashboard', 'reservations', 'availability', 'contracts', 'pricing', 'customers', 'vehicles', 'vehicle-categories', 'vehicle-blocks', 'maintenance', 'insurance', 'finance', 'reports', 'tenant', 'agencies', 'users', 'audit'],
            'agency-manager' => ['dashboard', 'reservations', 'availability', 'contracts', 'pricing', 'customers', 'vehicles', 'vehicle-categories', 'vehicle-blocks', 'maintenance', 'insurance', 'finance', 'reports', 'agencies', 'users', 'audit'],
            'rental-agent' => ['dashboard', 'reservations', 'availability', 'contracts', 'pricing', 'customers', 'maintenance', 'insurance', 'finance', 'agencies', 'users'],
            'fleet-manager' => ['dashboard', 'reservations', 'availability', 'contracts', 'vehicles', 'vehicle-categories', 'vehicle-blocks', 'maintenance', 'insurance', 'finance', 'agencies'],
            'accountant' => ['dashboard', 'reservations', 'availability', 'contracts', 'pricing', 'finance', 'reports', 'agencies'],
            'viewer-auditor' => ['dashboard', 'reservations', 'availability', 'contracts', 'pricing', 'customers', 'vehicles', 'vehicle-categories', 'maintenance', 'insurance', 'finance', 'reports', 'agencies', 'users', 'audit'],
        ];

        foreach ($expected as $role => $keys) {
            $response = $this->actingAs($this->fixture($role)['user'])->get(route('dashboard'))->assertOk();
            foreach ($keys as $key) {
                $this->assertSame(2, substr_count($response->getContent(), 'data-nav-key="'.$key.'"'), $role.' : '.$key);
            }
            $response->assertSee('data-nav-key="dashboard"', false)
                ->assertSee('aria-current="page"', false)
                ->assertSee('Navigation principale')
                ->assertSee('Navigation mobile');
        }

        $platform = User::factory()->create(['tenant_id' => null, 'agency_id' => null, 'role_id' => null, 'is_platform_admin' => true]);
        $platformResponse = $this->actingAs($platform)->get(route('platform.dashboard'))->assertOk();
        $this->assertSame(2, substr_count($platformResponse->getContent(), 'data-nav-key="platform-dashboard"'));
        $this->assertSame(2, substr_count($platformResponse->getContent(), 'data-nav-key="platform-tenants"'));
        $platformResponse->assertDontSee('data-nav-key="reservations"', false);
    }

    public function test_shell_has_landmarks_skip_link_accessible_flash_and_account_context(): void
    {
        $fixture = $this->fixture('agency-manager');

        $this->actingAs($fixture['user'])->withSession(['status' => 'Opération terminée.'])->get(route('dashboard'))
            ->assertOk()
            ->assertSee('href="#contenu"', false)
            ->assertSee('<main id="contenu"', false)
            ->assertSee('<header', false)
            ->assertSee('<aside', false)
            ->assertSee('<nav', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee($fixture['tenant']->name)
            ->assertSee($fixture['agency']->name)
            ->assertSee(UiLabel::get('agency-manager'));
    }

    public function test_statuses_and_business_values_are_presented_with_human_labels(): void
    {
        $badge = Blade::render('<x-status-badge value="return_pending" />');

        $this->assertStringContainsString('Retour à traiter', $badge);
        $this->assertStringContainsString('rounded-full', $badge);
        $this->assertStringContainsString('bg-current', $badge);
        $this->assertSame('Bloc manuel', UiLabel::blockType('manual'));
        $this->assertSame('Manuelle', UiLabel::get('manual'));
    }

    public function test_viewer_mutations_are_hidden_and_direct_financial_write_remains_forbidden(): void
    {
        $fixture = $this->fixture('viewer-auditor');

        $this->actingAs($fixture['user'])->get(route('finance.index'))
            ->assertOk()
            ->assertDontSee('Créer une dépense')
            ->assertDontSee('Approuver');
        $this->actingAs($fixture['user'])->post(route('finance.expenses.store'), [
            'agency_id' => $fixture['agency']->id,
            'category' => 'administration',
            'description' => 'Écriture interdite',
            'amount' => '10.00',
            'currency' => 'MAD',
            'expense_date' => today()->toDateString(),
        ])->assertForbidden();
    }

    public function test_scope_fields_are_not_changed_and_agency_filters_are_server_scoped(): void
    {
        $fixture = $this->fixture('agency-manager');
        $otherAgency = app(TenantContext::class)->run($fixture['tenant'], fn () => Agency::factory()->create(['name' => 'Agence non autorisée E1']));
        $original = $fixture['user']->only(['tenant_id', 'agency_id', 'role_id', 'is_platform_admin']);

        $this->actingAs($fixture['user'])->patch(route('profile.update'), [
            'name' => $fixture['user']->name,
            'email' => $fixture['user']->email,
            'tenant_id' => 999999,
            'agency_id' => $otherAgency->id,
            'role_id' => 999999,
            'is_platform_admin' => true,
        ])->assertRedirect(route('profile.edit'));

        $this->assertSame($original, $fixture['user']->refresh()->only(array_keys($original)));
        $this->actingAs($fixture['user'])->get(route('availability.index'))
            ->assertOk()
            ->assertSee($fixture['agency']->name)
            ->assertDontSee($otherAgency->name);
    }

    public function test_priority_empty_pages_render_without_sensitive_or_technical_data(): void
    {
        $fixture = $this->fixture('tenant-owner');
        $routes = ['dashboard', 'availability.index', 'reservations.index', 'contracts.index', 'vehicles.index', 'customers.index', 'finance.index', 'maintenance.index', 'insurance.index', 'reports.index'];

        foreach ($routes as $route) {
            $response = $this->actingAs($fixture['user'])->get(route($route))->assertOk();
            $response->assertDontSee('DB_PASSWORD')
                ->assertDontSee('APP_KEY')
                ->assertDontSee('SQLSTATE')
                ->assertDontSee('identity_number')
                ->assertDontSee('licence_number')
                ->assertDontSee('storage/app/private');
        }

        $this->actingAs($fixture['user'])->get(route('reservations.index'))
            ->assertSee('Aucune réservation')
            ->assertSee('0 résultat');
    }

    public function test_error_pages_have_a_correlation_reference_and_no_internal_trace(): void
    {
        $response = $this->get('/page-rentfleet-inexistante')->assertNotFound()->assertSee('Page introuvable');
        $correlationId = $response->headers->get('X-Correlation-ID');

        $this->assertNotEmpty($correlationId);
        $response->assertSee($correlationId)->assertDontSee('Stack trace')->assertDontSee('SQLSTATE');
    }

    public function test_dead_breeze_artifacts_corrupt_encoding_and_public_routes_are_absent(): void
    {
        $this->assertFileDoesNotExist(resource_path('views/auth/register.blade.php'));
        $this->assertFileDoesNotExist(resource_path('views/welcome.blade.php'));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Auth/RegisteredUserController.php'));

        $routeSignatures = collect(Route::getRoutes())->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri().' '.($route->getName() ?? ''))->join("\n");
        $this->assertDoesNotMatchRegularExpression('/\b(register|signup|profile\.destroy)\b/i', $routeSignatures);
        $this->assertStringNotContainsString('storage/', $routeSignatures);

        $files = collect(['app', 'config', 'database', 'docs', 'resources', 'routes', 'tests'])
            ->flatMap(fn (string $directory) => File::allFiles(base_path($directory)))
            ->merge([new \SplFileInfo(base_path('README.md')), new \SplFileInfo(base_path('AGENTS.md'))])
            ->filter(fn ($file) => in_array($file->getExtension(), ['php', 'blade.php', 'md', 'js', 'css'], true))
            ->filter(fn ($file) => $file->isFile());
        foreach ($files as $file) {
            $contents = File::get($file->getPathname());
            $this->assertDoesNotMatchRegularExpression('/\x{00C3}(?:\x{0192}|\x{201A}|\x{201E}|\x{2026}|\x{2020}|\x{2021}|\x{02C6}|\x{2030}|\x{0160}|\x{2039}|\x{0152}|\x{017D}|\x{0080}|\x{0081}|\x{0082}|\x{0083}|\x{0084}|\x{0085}|\x{0086}|\x{0087}|\x{0088}|\x{0089}|\x{008A}|\x{008B}|\x{008C}|\x{008D}|\x{008E}|\x{008F}|\x{0090}|\x{0091}|\x{0092}|\x{0093}|\x{0094}|\x{0095}|\x{0096}|\x{0097}|\x{0098}|\x{0099}|\x{009A}|\x{009B}|\x{009C}|\x{009D}|\x{009E}|\x{009F})|\x{FFFD}/u', $contents, $file->getPathname());
        }
    }

    public function test_all_blade_views_compile_and_professional_components_exist(): void
    {
        foreach (File::allFiles(resource_path('views')) as $file) {
            if ($file->getExtension() === 'php' && str_ends_with($file->getFilename(), '.blade.php')) {
                Blade::compileString(File::get($file->getPathname()));
            }
        }

        foreach (['brand-logo', 'mobile-navigation', 'flash-message', 'field-error', 'filter-panel', 'responsive-table', 'action-group', 'confirmation-button', 'section-card', 'metadata-list', 'timeline', 'password-field'] as $component) {
            $this->assertFileExists(resource_path('views/components/'.$component.'.blade.php'));
        }

        $this->addToAssertionCount(1);
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
