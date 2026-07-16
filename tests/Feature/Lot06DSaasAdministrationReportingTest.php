<?php

namespace Tests\Feature;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\CreateDriver;
use App\Actions\Finance\AllocatePaymentToInvoice;
use App\Actions\Finance\ApproveExpense;
use App\Actions\Finance\CreateExpense;
use App\Actions\Finance\CreateInvoiceFromReturnedContract;
use App\Actions\Finance\IssueInvoice;
use App\Actions\Finance\PostPayment;
use App\Actions\Finance\RecordDepositReceipt;
use App\Actions\Finance\RecordPayment;
use App\Actions\Platform\ProvisionTenant;
use App\Actions\Rentals\CreateRentalContractFromReservation;
use App\Actions\Reservations\ConfirmReservation;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Vehicles\CreateVehicle;
use App\Enums\CustomerType;
use App\Enums\RentalContractStatus;
use App\Enums\TenantStatus;
use App\Enums\VerificationStatus;
use App\Models\Agency;
use App\Models\MaintenanceOrder;
use App\Models\Permission;
use App\Models\PricingRule;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VehicleCategory;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class Lot06DSaasAdministrationReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_platform_provisioning_is_atomic_secure_and_forbidden_to_tenant_users(): void
    {
        $platform = User::factory()->create(['tenant_id' => null, 'agency_id' => null, 'role_id' => null, 'is_platform_admin' => true]);
        $data = $this->provisioningData('nouveau-locataire', 'owner@nouveau.test');
        $response = $this->actingAs($platform)->post(route('platform.tenants.store'), $data);

        $response->assertOk()->assertViewIs('shared.temporary-password')->assertHeader('Cache-Control', 'no-store, private');
        $tenant = Tenant::where('slug', 'nouveau-locataire')->firstOrFail();
        $owner = User::where('tenant_id', $tenant->id)->where('email', 'owner@nouveau.test')->firstOrFail();
        $temporaryPassword = $response->viewData('temporaryPassword');
        $this->assertDatabaseHas('agencies', ['tenant_id' => $tenant->id, 'code' => 'INIT', 'is_active' => true]);
        $this->assertSame('tenant-owner', $owner->role->slug);
        $this->assertTrue($owner->must_change_password);
        $this->assertTrue(Hash::check($temporaryPassword, $owner->password));
        $this->assertFalse(Hash::check('password', $owner->password));
        $this->assertStringNotContainsString($temporaryPassword, DB::table('audit_logs')->where('tenant_id', $tenant->id)->get()->toJson());

        $duplicateEmail = User::factory()->create()->email;
        try {
            app(ProvisionTenant::class)->handle($this->provisioningData('rollback-tenant', $duplicateEmail), $platform->id);
            $this->fail('Le provisioning devait échouer sur l’unicité de l’e-mail.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('tenants', ['slug' => 'rollback-tenant']);
            $this->assertDatabaseMissing('agencies', ['code' => 'INIT', 'name' => 'Agence Initiale Rollback Tenant']);
        }

        $fixture = $this->tenantFixture();
        $this->actingAs($fixture['user'])->get(route('platform.dashboard'))->assertForbidden();
    }

    public function test_temporary_password_must_be_changed_on_first_login(): void
    {
        $platform = User::factory()->create(['tenant_id' => null, 'agency_id' => null, 'role_id' => null, 'is_platform_admin' => true]);
        $response = $this->actingAs($platform)->post(route('platform.tenants.store'), $this->provisioningData('premiere-connexion', 'first@login.test'));
        $temporaryPassword = $response->viewData('temporaryPassword');
        $owner = User::where('email', 'first@login.test')->firstOrFail();

        Auth::logout();
        $this->post('/login', ['email' => $owner->email, 'password' => $temporaryPassword])->assertRedirect(route('password.change-required', absolute: false));
        $this->get(route('dashboard'))->assertRedirect(route('password.change-required'));
        $this->put(route('password.change-required.update'), [
            'current_password' => $temporaryPassword,
            'password' => 'Nouveau-Mot-De-Passe-06D!',
            'password_confirmation' => 'Nouveau-Mot-De-Passe-06D!',
        ])->assertRedirect(route('dashboard'));

        $this->assertFalse($owner->refresh()->must_change_password);
        $this->assertTrue(Hash::check('Nouveau-Mot-De-Passe-06D!', $owner->password));
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $owner->tenant_id, 'action' => 'user.initial_password_changed']);
    }

    public function test_suspension_refuses_login_blocks_existing_sessions_preserves_data_and_is_audited(): void
    {
        $platform = User::factory()->create(['tenant_id' => null, 'agency_id' => null, 'role_id' => null, 'is_platform_admin' => true]);
        $fixture = $this->tenantFixture();
        $agencyId = $fixture['agency']->id;

        $this->actingAs($platform)->post(route('platform.tenants.suspend', $fixture['tenant']), ['reason' => 'Contrôle administratif demandé'])->assertRedirect();
        $this->assertSame(TenantStatus::Suspended, $fixture['tenant']->refresh()->status);
        $this->assertDatabaseHas('agencies', ['id' => $agencyId, 'tenant_id' => $fixture['tenant']->id]);
        $this->actingAs($fixture['user'])->get(route('dashboard'))->assertForbidden();

        Auth::logout();
        $this->post('/login', ['email' => $fixture['user']->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $audit = DB::table('audit_logs')->where('tenant_id', $fixture['tenant']->id)->where('action', 'platform.tenant.suspended')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('password', json_encode($audit));
        $this->assertStringNotContainsString('secret', json_encode($audit));

        $this->actingAs($platform)->post(route('platform.tenants.reactivate', $fixture['tenant']))->assertRedirect();
        $this->assertSame(TenantStatus::Active, $fixture['tenant']->refresh()->status);
        $this->actingAs($fixture['user'])->get(route('dashboard'))->assertOk();
    }

    public function test_user_administration_enforces_scope_roles_last_owner_and_deactivation(): void
    {
        $fixture = $this->tenantFixture();
        $agentRole = Role::where('slug', 'rental-agent')->firstOrFail();
        $create = $this->actingAs($fixture['user'])->post(route('users.store'), [
            'name' => 'Agent créé', 'email' => 'agent.created@test.local', 'role_id' => $agentRole->id,
            'agency_id' => $fixture['agency']->id, 'is_active' => '1',
        ]);
        $create->assertOk()->assertViewIs('shared.temporary-password');
        $agent = User::where('email', 'agent.created@test.local')->firstOrFail();
        $this->assertTrue(Hash::check($create->viewData('temporaryPassword'), $agent->password));
        $this->assertTrue($agent->must_change_password);

        $reset = $this->actingAs($fixture['user'])->post(route('users.reset-password', $agent));
        $reset->assertOk()->assertViewIs('shared.temporary-password')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertTrue(Hash::check($reset->viewData('temporaryPassword'), $agent->refresh()->password));
        $this->assertTrue($agent->must_change_password);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $fixture['tenant']->id, 'action' => 'user.password_reset', 'auditable_id' => $agent->id]);

        $this->actingAs($fixture['user'])->put(route('users.update', $fixture['user']), [
            'name' => 'Auto élévation', 'email' => $fixture['user']->email, 'role_id' => $fixture['user']->role_id,
            'agency_id' => null, 'is_active' => '1',
        ])->assertForbidden();

        $other = $this->tenantFixture();
        $this->actingAs($fixture['user'])->put(route('users.update', $other['user']), [
            'name' => $other['user']->name, 'email' => $other['user']->email, 'role_id' => $other['user']->role_id,
            'agency_id' => null, 'is_active' => '1',
        ])->assertForbidden();

        $manager = User::factory()->create(['tenant_id' => $fixture['tenant']->id, 'agency_id' => $fixture['agency']->id, 'role_id' => Role::where('slug', 'agency-manager')->value('id')]);
        $this->actingAs($manager)->post(route('users.store'), [
            'name' => 'Owner interdit', 'email' => 'forbidden-owner@test.local', 'role_id' => $fixture['user']->role_id,
            'agency_id' => null, 'is_active' => '1',
        ])->assertForbidden();
        $this->actingAs($manager)->post(route('users.store'), [
            'name' => 'Plateforme interdite', 'email' => 'platform-forbidden@test.local', 'role_id' => $agentRole->id,
            'agency_id' => $fixture['agency']->id, 'is_active' => '1', 'is_platform_admin' => '1',
        ])->assertSessionHasErrors('is_platform_admin');
        $otherAgency = app(TenantContext::class)->run($fixture['tenant'], fn () => Agency::factory()->create());
        $this->actingAs($manager)->post(route('users.store'), [
            'name' => 'Agence interdite', 'email' => 'foreign-agency@test.local', 'role_id' => $agentRole->id,
            'agency_id' => $otherAgency->id, 'is_active' => '1',
        ])->assertForbidden();

        $adminRole = Role::forceCreate(['tenant_id' => $fixture['tenant']->id, 'name' => 'Administrateur utilisateurs', 'slug' => 'user-admin', 'is_system' => false]);
        $adminRole->permissions()->attach(Permission::where('slug', 'user.manage')->value('id'));
        $admin = User::factory()->create(['tenant_id' => $fixture['tenant']->id, 'agency_id' => null, 'role_id' => $adminRole->id]);
        $this->actingAs($admin)->put(route('users.update', $fixture['user']), [
            'name' => $fixture['user']->name, 'email' => $fixture['user']->email, 'role_id' => $fixture['user']->role_id,
            'agency_id' => null, 'is_active' => '0',
        ])->assertSessionHasErrors('is_active');
        $this->assertTrue($fixture['user']->refresh()->is_active);

        $this->actingAs($fixture['user'])->put(route('users.update', $agent), [
            'name' => $agent->name, 'email' => $agent->email, 'role_id' => $agentRole->id,
            'agency_id' => $fixture['agency']->id, 'is_active' => '0',
        ])->assertRedirect(route('users.index'));
        $this->assertFalse($agent->refresh()->is_active);
        $this->assertNotNull($agent->fresh());
    }

    public function test_tenant_settings_are_owner_only_scoped_and_audited(): void
    {
        $fixture = $this->tenantFixture();
        $payload = [
            'name' => 'Atlas Location', 'legal_name' => 'Atlas Location SARL', 'email' => 'contact@atlas.test',
            'phone' => '+212500000100', 'address' => 'Casablanca', 'currency' => 'MAD', 'timezone' => 'Africa/Casablanca',
        ];

        $this->actingAs($fixture['user'])->patch(route('tenant.update'), $payload)->assertRedirect();
        $tenant = $fixture['tenant']->refresh();
        $this->assertSame('Atlas Location', $tenant->name);
        $this->assertSame('MAD', $tenant->settings['currency']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'tenant.settings.updated']);

        $this->actingAs($fixture['user'])->patch(route('tenant.update'), [...$payload, 'status' => 'suspended'])
            ->assertSessionHasErrors('status');
        $this->assertSame(TenantStatus::Active, $tenant->refresh()->status);

        $manager = User::factory()->create([
            'tenant_id' => $tenant->id, 'agency_id' => $fixture['agency']->id,
            'role_id' => Role::where('slug', 'agency-manager')->value('id'),
        ]);
        $this->actingAs($manager)->patch(route('tenant.update'), $payload)->assertForbidden();
    }

    public function test_platform_dashboard_exposes_only_aggregate_metrics_and_operational_alerts(): void
    {
        $platform = User::factory()->create(['tenant_id' => null, 'agency_id' => null, 'role_id' => null, 'is_platform_admin' => true]);
        $tenantWithoutOwner = Tenant::factory()->create(['name' => 'Tenant sans responsable']);

        $response = $this->actingAs($platform)->get(route('platform.dashboard'));
        $response->assertOk()->assertViewIs('platform.dashboard');
        $this->assertSame(1, $response->viewData('metrics')['Tenants']);
        $this->assertTrue($response->viewData('alerts')->contains(fn (array $alert) => $alert['tenant']->is($tenantWithoutOwner) && $alert['missing_owner']));
        $response->assertDontSee('password')->assertDontSee('DB_PASSWORD');
    }

    public function test_agency_deactivation_rejects_active_dependencies_and_never_deletes(): void
    {
        $fixture = $this->tenantFixture();
        $otherAgency = app(TenantContext::class)->run($fixture['tenant'], fn () => Agency::factory()->create());
        $vehicle = app(TenantContext::class)->run($fixture['tenant'], function () use ($fixture) {
            $category = VehicleCategory::create(['code' => 'AGENCY-06D', 'name' => 'Agence 06D', 'is_active' => true]);

            return app(CreateVehicle::class)->handle([
                'agency_id' => $fixture['agency']->id, 'vehicle_category_id' => $category->id, 'registration_number' => 'AGENCY-06D',
                'brand' => 'Dacia', 'model' => 'Logan', 'production_year' => 2025, 'fuel_type' => 'diesel', 'transmission' => 'manual', 'current_mileage' => 1000,
            ], $fixture['user']->id);
        }, $fixture['agency']->id);
        app(TenantContext::class)->run($fixture['tenant'], function () use ($vehicle, $fixture): void {
            MaintenanceOrder::create([
                'agency_id' => $fixture['agency']->id, 'vehicle_id' => $vehicle->id,
                'maintenance_number' => 'MNT-06D-ACTIVE', 'maintenance_type' => 'preventive', 'priority' => 'normal',
                'status' => 'planned', 'title' => 'Maintenance bloquante', 'estimated_cost' => '0.00', 'actual_cost' => '0.00',
                'created_by' => $fixture['user']->id,
            ]);
        }, $fixture['agency']->id);
        $payload = ['code' => $fixture['agency']->code, 'name' => $fixture['agency']->name, 'email' => '', 'phone' => '', 'address' => '', 'is_active' => '0'];

        $this->actingAs($fixture['user'])->put(route('agencies.update', $fixture['agency']), $payload)->assertSessionHasErrors('is_active');
        $this->assertTrue($fixture['agency']->refresh()->is_active);

        app(TenantContext::class)->run($fixture['tenant'], fn () => MaintenanceOrder::where('maintenance_number', 'MNT-06D-ACTIVE')->firstOrFail()->forceFill(['status' => 'cancelled'])->save(), $fixture['agency']->id);
        $this->actingAs($fixture['user'])->put(route('agencies.update', $fixture['agency']), $payload)->assertRedirect(route('agencies.index'));
        $this->assertFalse($fixture['agency']->refresh()->is_active);
        $this->assertNotNull(Agency::withoutGlobalScopes()->find($fixture['agency']->id));
        $this->assertTrue($otherAgency->refresh()->is_active);
    }

    public function test_reservation_csv_export_is_filtered_isolated_utf8_and_formula_safe(): void
    {
        $fixture = $this->tenantFixture();
        $business = $this->businessFixture($fixture, '=FORMULE');
        $foreign = $this->tenantFixture();
        $foreignBusiness = $this->businessFixture($foreign, 'Étranger');
        $from = $business['reservation']->starts_at->subDay()->toDateString();
        $to = $business['reservation']->ends_at->addDay()->toDateString();

        $agent = User::factory()->create(['tenant_id' => $fixture['tenant']->id, 'agency_id' => $fixture['agency']->id, 'role_id' => Role::where('slug', 'rental-agent')->value('id')]);
        $this->actingAs($agent)->get(route('reservations.export', ['date_from' => $from, 'date_to' => $to]))->assertForbidden();

        $response = $this->actingAs($fixture['user'])->get(route('reservations.export', [
            'date_from' => $from, 'date_to' => $to, 'agency_id' => $fixture['agency']->id,
            'status' => 'confirmed', 'vehicle_category_id' => $business['category']->id, 'vehicle_id' => $business['vehicle']->id,
        ]));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $response->assertHeader('content-disposition', 'attachment; filename=reservations_'.$from.'_'.$to.'.csv');
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Numéro;Agence;Statut', $csv);
        $this->assertStringContainsString($business['reservation']->reservation_number, $csv);
        $this->assertStringContainsString("'=FORMULE", $csv);
        $this->assertStringNotContainsString(';=FORMULE', $csv);
        $this->assertStringNotContainsString('LIC-SENSITIVE', $csv);
        $this->assertStringNotContainsString('Étranger Client', $csv);

        $otherAgency = app(TenantContext::class)->run($fixture['tenant'], fn () => Agency::factory()->create());
        $manager = User::factory()->create(['tenant_id' => $fixture['tenant']->id, 'agency_id' => $fixture['agency']->id, 'role_id' => Role::where('slug', 'agency-manager')->value('id')]);
        $this->actingAs($manager)->get(route('reservations.export', ['date_from' => $from, 'date_to' => $to, 'agency_id' => $otherAgency->id]))->assertForbidden();
    }

    public function test_reports_enforce_permission_agency_scope_and_exact_postgresql_amounts(): void
    {
        $fixture = $this->tenantFixture();
        $business = $this->businessFixture($fixture, 'Rapport');
        $finance = app(TenantContext::class)->run($fixture['tenant'], function () use ($fixture, $business): array {
            $contract = app(CreateRentalContractFromReservation::class)->handle($business['reservation'], $fixture['user']->id);
            $contract->forceFill(['status' => RentalContractStatus::Returned, 'returned_at' => now()])->save();
            $invoice = app(IssueInvoice::class)->handle(app(CreateInvoiceFromReturnedContract::class)->handle($contract, $fixture['user']->id), $fixture['user']->id);
            $payment = app(RecordPayment::class)->handle([
                'agency_id' => $fixture['agency']->id, 'rental_contract_id' => $contract->id, 'customer_id' => $business['customer']->id,
                'payment_method' => 'cash', 'amount' => '100.00', 'currency' => 'MAD', 'idempotency_key' => 'report-payment-06d',
            ], $fixture['user']->id);
            app(AllocatePaymentToInvoice::class)->handle($payment, $invoice, '100.00');
            app(PostPayment::class)->handle($payment, $fixture['user']->id);
            app(RecordDepositReceipt::class)->handle($contract, '50.00', 'report-deposit-06d', $fixture['user']->id);
            $expense = app(CreateExpense::class)->handle([
                'agency_id' => $fixture['agency']->id, 'category' => 'administration', 'description' => 'Dépense rapport',
                'amount' => '75.00', 'currency' => 'MAD', 'expense_date' => today()->toDateString(),
            ], $fixture['user']->id);
            app(ApproveExpense::class)->handle($expense, $fixture['user']->id);

            return ['invoice' => $invoice->refresh()];
        }, $fixture['agency']->id);

        $foreign = $this->tenantFixture();
        $this->businessFixture($foreign, 'Invisible');
        $from = today()->subDay()->toDateString();
        $to = today()->addDays(5)->toDateString();
        $agent = User::factory()->create(['tenant_id' => $fixture['tenant']->id, 'agency_id' => $fixture['agency']->id, 'role_id' => Role::where('slug', 'rental-agent')->value('id')]);
        $this->actingAs($agent)->get(route('reports.index', ['date_from' => $from, 'date_to' => $to]))->assertForbidden();

        $response = $this->actingAs($fixture['user'])->get(route('reports.index', ['date_from' => $from, 'date_to' => $to, 'agency_id' => $fixture['agency']->id]));
        $response->assertOk()->assertViewIs('reports.index');
        $report = $response->viewData('report');
        $this->assertSame(1, $report['financial']['issued_invoices']);
        $this->assertSame($finance['invoice']->total_amount, $report['financial']['invoiced_amount']);
        $this->assertSame('100.00', $report['financial']['allocated_collections']);
        $this->assertSame($finance['invoice']->balance_due, $report['financial']['outstanding_balance']);
        $this->assertSame('50.00', $report['financial']['held_deposits']);
        $this->assertSame('75.00', $report['financial']['approved_expenses']);
        $this->assertSame(1, $report['operational']['contracts']['Retourné']);
        $this->assertSame(1, $report['operational']['reservations']['Convertie']);
        $this->assertSame('pgsql', DB::connection()->getDriverName());

        $otherAgency = app(TenantContext::class)->run($fixture['tenant'], fn () => Agency::factory()->create());
        $manager = User::factory()->create(['tenant_id' => $fixture['tenant']->id, 'agency_id' => $fixture['agency']->id, 'role_id' => Role::where('slug', 'agency-manager')->value('id')]);
        $this->actingAs($manager)->get(route('reports.index', ['date_from' => $from, 'date_to' => $to, 'agency_id' => $otherAgency->id]))->assertForbidden();
    }

    private function provisioningData(string $slug, string $ownerEmail): array
    {
        return [
            'name' => str($slug)->headline()->toString(), 'slug' => $slug, 'legal_name' => str($slug)->headline()->append(' SARL')->toString(),
            'email' => 'contact@'.$slug.'.test', 'phone' => '+212500000000', 'address' => 'Adresse fictive', 'currency' => 'MAD', 'timezone' => 'Africa/Casablanca',
            'agency_code' => 'INIT', 'agency_name' => 'Agence Initiale '.str($slug)->headline(), 'agency_email' => 'agence@'.$slug.'.test',
            'agency_phone' => '+212500000001', 'agency_address' => 'Adresse agence fictive', 'owner_name' => 'Propriétaire Initial', 'owner_email' => $ownerEmail,
        ];
    }

    private function tenantFixture(string $roleSlug = 'tenant-owner'): array
    {
        $tenant = Tenant::factory()->create();
        $agency = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create());
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => $roleSlug === 'tenant-owner' ? null : $agency->id, 'role_id' => $role->id, 'must_change_password' => false]);

        return compact('tenant', 'agency', 'user');
    }

    private function businessFixture(array $fixture, string $customerFirstName): array
    {
        return app(TenantContext::class)->run($fixture['tenant'], function () use ($fixture, $customerFirstName): array {
            $category = VehicleCategory::create(['code' => 'C'.uniqid(), 'name' => 'Catégorie 06D', 'is_active' => true]);
            $vehicle = app(CreateVehicle::class)->handle([
                'agency_id' => $fixture['agency']->id, 'vehicle_category_id' => $category->id, 'registration_number' => 'RPT-'.uniqid(),
                'brand' => 'Dacia', 'model' => 'Logan', 'production_year' => 2025, 'fuel_type' => 'diesel', 'transmission' => 'manual', 'current_mileage' => 1000,
            ], $fixture['user']->id);
            $customer = app(CreateCustomer::class)->handle([
                'agency_id' => $fixture['agency']->id, 'customer_type' => CustomerType::Individual,
                'first_name' => $customerFirstName, 'last_name' => 'Client', 'verification_status' => VerificationStatus::Verified,
            ]);
            $driver = app(CreateDriver::class)->handle($customer, [
                'first_name' => 'Conducteur', 'last_name' => 'Test', 'licence_number' => 'LIC-SENSITIVE-'.uniqid(),
                'licence_expires_at' => today()->addYears(2), 'verification_status' => VerificationStatus::Verified, 'is_primary' => true,
            ]);
            PricingRule::create([
                'agency_id' => null, 'vehicle_category_id' => $category->id, 'name' => 'Tarif 06D', 'daily_rate' => '400.00',
                'deposit_amount' => '300.00', 'minimum_days' => 1, 'maximum_days' => 30, 'valid_from' => today()->subYear(),
                'priority' => 0, 'currency' => 'MAD', 'conditions' => [], 'is_active' => true, 'created_by' => $fixture['user']->id,
            ]);
            $start = CarbonImmutable::now()->addDays(2)->startOfHour();
            $reservation = app(CreateReservation::class)->handle([
                'agency_id' => $fixture['agency']->id, 'customer_id' => $customer->id, 'driver_id' => $driver->id,
                'vehicle_category_id' => $category->id, 'vehicle_id' => $vehicle->id, 'starts_at' => $start,
                'ends_at' => $start->addDay(), 'status' => 'draft',
            ], $fixture['user']->id);
            app(ConfirmReservation::class)->handle($reservation, $fixture['user']->id);

            return compact('category', 'vehicle', 'customer', 'driver', 'reservation');
        }, $fixture['agency']->id);
    }
}
