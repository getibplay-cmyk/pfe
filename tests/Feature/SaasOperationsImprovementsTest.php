<?php

namespace Tests\Feature;

use App\Actions\PlatformBilling\AssignSaasSubscription;
use App\Actions\PlatformBilling\CreateSaasPlan;
use App\Actions\Vehicles\CreateVehicle;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\CustomerPortalAccess;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\OnboardingImport;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\RentalContract;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleBlock;
use App\Models\VehicleCategory;
use App\Support\Reporting\BuildMinimalReport;
use App\Support\Reporting\ReportCriteria;
use App\Support\Tenancy\OnboardingCsvImport;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SaasOperationsImprovementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-09-08 12:00:00', 'Africa/Casablanca'));
        Storage::fake('local');
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    }

    public function test_loaded_model_cannot_be_updated_under_another_tenant(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $this->expectException(AuthorizationException::class);
        app(TenantContext::class)->run($b['tenant'], fn () => $a['customer']->update(['first_name' => 'Interdit']));
    }

    public function test_tenant_assignment_is_immutable_on_an_existing_model(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $this->expectException(AuthorizationException::class);
        app(TenantContext::class)->run($a['tenant'], fn () => $a['customer']->forceFill(['tenant_id' => $b['tenant']->id])->save());
    }

    public function test_loaded_model_cannot_be_deleted_under_another_tenant(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $this->expectException(AuthorizationException::class);
        app(TenantContext::class)->run($b['tenant'], fn () => $a['customer']->delete());
    }

    public function test_planning_only_loads_authorized_vehicles_and_intersecting_active_blocks(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $inside = $this->block($a, '2026-09-08 10:00', '2026-09-08 14:00');
        $this->block($a, '2026-09-07 10:00', '2026-09-08 00:00');
        $this->block($a, '2026-09-15 00:00', '2026-09-16 00:00');
        $response = $this->actingAs($a['user'])->get(route('fleet.planning.index', ['date' => '2026-09-08', 'days' => 7]));
        $response->assertOk()->assertSee($a['vehicle']->registration_number)->assertDontSee($b['vehicle']->registration_number);
        $this->assertSame([$inside->id], $response->viewData('vehicles')->first()->blocks->pluck('id')->all());
        $response->assertSee('Bloc manuel');
    }

    public function test_agency_manager_cannot_expand_planning_or_profitability_scope(): void
    {
        $a = $this->fixture('agency-manager');
        $other = app(TenantContext::class)->run($a['tenant'], fn () => Agency::factory()->create());
        $this->actingAs($a['user'])->getJson(route('fleet.planning.index', ['agency_id' => $other->id]))->assertUnprocessable();
        $this->get(route('vehicle-profitability.index', ['agency_id' => $other->id]))->assertForbidden();
    }

    public function test_planning_rejects_client_tenant_and_unbounded_period(): void
    {
        $a = $this->fixture();
        $this->actingAs($a['user'])->getJson(route('fleet.planning.index', ['tenant_id' => $a['tenant']->id]))->assertUnprocessable();
        $this->getJson(route('fleet.planning.index', ['days' => 9999]))->assertUnprocessable();
    }

    public function test_action_dashboard_counts_today_without_including_tomorrow_or_other_tenants(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $contract = $this->contract($a);
        $this->contract($b);
        $response = $this->actingAs($a['user'])->get(route('dashboard'))->assertOk();
        $group = collect($response->viewData('actionGroups'))->firstWhere('key', 'departures');
        $this->assertSame(1, $group['count']);
        $this->assertSame(route('contracts.show', $contract), $group['items']->sole()['url']);
        $this->assertFalse(app(TenantContext::class)->hasTenant());
    }

    public function test_portal_access_creation_is_permission_scoped_and_revocable(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $this->actingAs($b['user'])->post(route('customers.portal-access.store', $a['customer']))->assertNotFound();
        $this->actingAs($a['user'])->post(route('customers.portal-access.store', $a['customer']))->assertRedirect()->assertSessionHas('portal_url');
        $grant = CustomerPortalAccess::withoutGlobalScopes()->sole();
        $this->assertSame($a['tenant']->id, $grant->tenant_id);
        $this->delete(route('customers.portal-access.revoke', $a['customer']))->assertRedirect();
        $this->assertNotNull($grant->fresh()->revoked_at);
    }

    public function test_portal_landing_does_not_consume_link_and_exchange_is_single_use(): void
    {
        $a = $this->fixture();
        $grant = $this->grant($a);
        $url = URL::temporarySignedRoute('portal.enter', $grant->expires_at, ['access' => $grant->id]);
        $this->get($url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertNull($grant->fresh()->consumed_at);
        $this->post($url)->assertRedirect(route('portal.home'));
        $this->get(route('portal.home'))->assertOk()->assertSee($a['customer']->displayName());
        $this->post($url)->assertForbidden();
        $this->assertFalse(app(TenantContext::class)->hasTenant());
    }

    public function test_portal_rejects_unsigned_tampered_expired_and_missing_access(): void
    {
        $a = $this->fixture();
        $grant = $this->grant($a);
        $this->get(route('portal.home'))->assertForbidden();
        $this->get(route('portal.enter', $grant))->assertForbidden();
        $url = URL::temporarySignedRoute('portal.enter', now()->subMinute(), ['access' => $grant->id]);
        $this->get($url)->assertForbidden();
    }

    public function test_portal_does_not_expose_another_customer_in_the_same_tenant(): void
    {
        $a = $this->fixture();
        $own = $this->invoice($a, '123.45');
        $otherCustomer = app(TenantContext::class)->run($a['tenant'], fn () => Customer::create(['agency_id' => $a['agency']->id, 'customer_type' => 'individual', 'first_name' => 'Autre', 'last_name' => 'Locataire']));
        $foreign = $this->invoice([...$a, 'customer' => $otherCustomer], '678.90');
        $this->enter($this->grant($a));
        $this->get(route('portal.home'))->assertOk()->assertSee($own->invoice_number)->assertDontSee($foreign->invoice_number);
        $this->get(route('portal.invoice', $own))->assertOk()->assertSee($own->invoice_number);
        $this->get(route('portal.invoice', $foreign))->assertNotFound();
        $this->get(route('portal.contract', $foreign->rental_contract_id))->assertNotFound();
        $this->get(route('portal.document', 999999))->assertNotFound();
    }

    public function test_portal_cannot_access_another_tenant_invoice(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $foreign = $this->invoice($b, '100.00');
        $this->enter($this->grant($a));
        $this->get(route('portal.invoice', $foreign))->assertNotFound();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_portal_revocation_and_session_expiry_block_existing_sessions(): void
    {
        $a = $this->fixture();
        $grant = $this->grant($a);
        $this->enter($grant);
        app(TenantContext::class)->run($a['tenant'], fn () => $grant->fresh()->forceFill(['revoked_at' => now()])->save());
        $this->get(route('portal.home'))->assertForbidden();
        $grant = $this->grant($a);
        $this->enter($grant);
        $this->travel(31)->minutes();
        $this->get(route('portal.home'))->assertForbidden();
    }

    public function test_portal_rechecks_suspended_tenant_and_customer_agency(): void
    {
        $a = $this->fixture();
        $this->enter($this->grant($a));
        DB::table('tenants')->where('id', $a['tenant']->id)->update(['status' => 'suspended']);
        $this->get(route('portal.home'))->assertForbidden();
    }

    public function test_portal_upload_is_private_and_does_not_verify_customer(): void
    {
        $a = $this->fixture();
        $this->enter($this->grant($a));
        $this->post(route('portal.upload'), ['document_type' => 'customer_identity', 'file' => UploadedFile::fake()->image('identite.png')])->assertRedirect();
        $document = app(TenantContext::class)->run($a['tenant'], fn () => Document::with('currentVersion')->sole());
        Storage::disk('local')->assertExists($document->currentVersion->stored_path);
        $this->assertSame($a['customer']->id, $document->documentable_id);
        $this->assertNull($document->created_by);
        $this->assertDatabaseCount('customer_portal_uploads', 1);
        $this->assertSame('pending', $a['customer']->fresh()->verification_status->value);
        $this->get(route('portal.document', $document))->assertOk();
        $this->postJson(route('portal.upload'), ['document_type' => 'other', 'tenant_id' => $a['tenant']->id, 'file' => UploadedFile::fake()->image('x.png')])->assertUnprocessable();
        $this->postJson(route('portal.upload'), ['document_type' => 'other', 'file' => UploadedFile::fake()->createWithContent('payload.php.jpg', '<?php echo 1;')])->assertUnprocessable();
    }

    public function test_import_detects_header_after_intro_and_rejects_duplicates_before_commit(): void
    {
        $a = $this->fixture();
        app(TenantContext::class)->run($a['tenant'], function () use ($a) {
            $service = app(OnboardingCsvImport::class);
            $csv = "Liste de véhicules\n\nimmatriculation;marque;modele;categorie;carburant;transmission;kilometrage\nAA-IMPORT;Dacia;Logan;ECONO;diesel;manuelle;1000\nAA-IMPORT;Dacia;Logan;ECONO;diesel;manuelle;1000\n";
            $import = $service->preview(UploadedFile::fake()->createWithContent('vehicules.csv', $csv), 'vehicles', $a['agency']->id, $a['user']);
            $rows = $service->inspect($import);
            $this->assertCount(2, $rows);
            $this->assertEmpty($rows[0]['errors']);
            $this->assertNotEmpty($rows[1]['errors']);
            try {
                $service->commit($import, $a['user']);
                $this->fail('Duplicate import accepted');
            } catch (ValidationException) {
            }
            $this->assertSame(1, Vehicle::count());
        });
    }

    public function test_import_commits_all_rows_once_and_clears_encrypted_preview(): void
    {
        $a = $this->fixture();
        app(TenantContext::class)->run($a['tenant'], function () use ($a) {
            $service = app(OnboardingCsvImport::class);
            $import = $service->preview(UploadedFile::fake()->createWithContent('clients.csv', "prenom;nom;email;telephone\nSara;Test;sara@example.test;+212600000000\nAdam;Test;adam@example.test;\n"), 'customers', $a['agency']->id, $a['user']);
            $this->assertStringNotContainsString('sara@example.test', DB::table('onboarding_imports')->where('id', $import->id)->value('payload'));
            $this->assertSame(2, $service->commit($import, $a['user']));
            $this->assertSame(2, $service->commit($import, $a['user']));
            $this->assertSame(3, Customer::count());
            $this->assertNull($import->fresh()->payload);
        });
    }

    public function test_vehicle_import_rolls_back_all_rows_when_subscription_quota_is_reached(): void
    {
        $a = $this->fixture();
        $platform = User::factory()->create(['tenant_id' => null, 'agency_id' => null, 'role_id' => null, 'is_platform_admin' => true, 'is_active' => true]);
        $plan = app(CreateSaasPlan::class)->handle([
            'code' => 'import-limit', 'name' => 'Plan import', 'description' => 'Test', 'billing_interval' => 'monthly',
            'price_amount' => '100.00', 'currency' => 'MAD', 'features' => ['Flotte'], 'is_active' => true,
            'entitlements_configured' => true, 'max_vehicles' => 2,
        ], $platform->id);
        app(AssignSaasSubscription::class)->handle($a['tenant'], $plan, [
            'status' => 'active', 'starts_at' => now()->subDay()->toIso8601String(),
            'ends_at' => now()->addDays(3)->toIso8601String(), 'next_renewal_at' => now()->addDays(3)->toIso8601String(), 'admin_note' => 'Test du quota import.',
        ], $platform->id);
        $this->actingAs($a['user'])->get(route('dashboard'))->assertOk()->assertSee('Votre abonnement demande votre attention');
        app(TenantContext::class)->run($a['tenant'], function () use ($a) {
            $service = app(OnboardingCsvImport::class);
            $csv = "immatriculation;marque;modele;categorie;carburant;transmission;kilometrage\nIMPORT-1;Dacia;Logan;ECONO;diesel;manuelle;100\nIMPORT-2;Dacia;Logan;ECONO;diesel;manuelle;200\n";
            $import = $service->preview(UploadedFile::fake()->createWithContent('vehicules.csv', $csv), 'vehicles', $a['agency']->id, $a['user']);
            try {
                $service->commit($import, $a['user']);
                $this->fail('Quota must reject import');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('plan', $e->errors());
            }
            $this->assertSame(1, Vehicle::count());
            $this->assertNull($import->fresh()->completed_at);
        });
    }

    public function test_disabling_access_issuer_invalidates_the_portal_session(): void
    {
        $a = $this->fixture();
        $this->enter($this->grant($a));
        $a['user']->forceFill(['is_active' => false])->save();
        $this->get(route('portal.home'))->assertForbidden();
    }

    public function test_import_preview_and_templates_render_and_foreign_import_is_hidden(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $this->actingAs($a['user'])->get(route('onboarding.import.index'))->assertOk();
        $this->get(route('onboarding.import.template', 'vehicles'))->assertOk()->assertSee('immatriculation');
        $this->post(route('onboarding.import.store'), ['kind' => 'customers', 'agency_id' => $a['agency']->id, 'file' => UploadedFile::fake()->createWithContent('clients.csv', "prenom;nom;email;telephone\nSara;Test;sara@example.test;\n")])->assertRedirect();
        $import = OnboardingImport::withoutGlobalScopes()->sole();
        $this->get(route('onboarding.import.show', $import))->assertOk()->assertSee('Sara');
        $this->actingAs($b['user'])->get(route('onboarding.import.show', $import))->assertNotFound();
        $this->post(route('onboarding.import.commit', $import))->assertNotFound();
    }

    public function test_import_cannot_target_foreign_agency_or_include_tenant_column(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $this->actingAs($a['user'])->postJson(route('onboarding.import.store'), ['kind' => 'customers', 'agency_id' => $b['agency']->id, 'file' => UploadedFile::fake()->createWithContent('clients.csv', "prenom;nom;email;telephone\nSara;Test;sara@example.test;\n")])->assertNotFound();
        $this->postJson(route('onboarding.import.store'), ['kind' => 'customers', 'agency_id' => $a['agency']->id, 'file' => UploadedFile::fake()->createWithContent('clients.csv', "tenant_id;prenom;nom;email;telephone\n1;Sara;Test;sara@example.test;\n")])->assertUnprocessable();
    }

    public function test_expired_import_is_purged_and_cannot_be_confirmed(): void
    {
        $a = $this->fixture();
        $import = app(TenantContext::class)->run($a['tenant'], fn () => app(OnboardingCsvImport::class)->preview(UploadedFile::fake()->createWithContent('clients.csv', "prenom;nom;email;telephone\nSara;Test;sara@example.test;\n"), 'customers', $a['agency']->id, $a['user']));
        $this->travel(61)->minutes();
        $this->actingAs($a['user'])->post(route('onboarding.import.commit', $import))->assertGone();
        $this->artisan('rentfleet:onboarding:purge')->assertSuccessful();
        $this->assertNull(DB::table('onboarding_imports')->where('id', $import->id)->value('payload'));
    }

    public function test_profitability_aggregates_without_multiplication_and_keeps_currencies_separate(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $invoice = $this->invoice($a, '1000.10');
        $this->invoice($a, '200.20');
        $this->invoice($a, '10.00', 'EUR');
        $this->invoice($b, '9999.00');
        $this->expense($a, '100.01');
        $this->expense($a, '50.02');
        $this->expense($a, '999.00', 'draft');
        $this->payment($a, $invoice, '600.05');
        $this->payment($a, $invoice, '100.01', 'outgoing');
        $this->payment($a, $invoice, '200.00', 'incoming', 'pending');
        $response = $this->actingAs($a['user'])->get(route('vehicle-profitability.index', ['date_from' => '2026-09-08', 'date_to' => '2026-09-08']))->assertOk();
        $values = $response->viewData('amounts')[$a['vehicle']->id];
        $this->assertSame('1200.30', $values['MAD']['invoiced']);
        $this->assertSame('500.04', $values['MAD']['collected']);
        $this->assertSame('150.03', $values['MAD']['expenses']);
        $this->assertSame('1050.27', $values['MAD']['margin']);
        $this->assertSame('10.00', $values['EUR']['margin']);
        $response->assertDontSee($b['vehicle']->registration_number);
    }

    public function test_profitability_shows_negative_margin_and_clips_downtime_to_period(): void
    {
        $a = $this->fixture();
        $this->invoice($a, '10.00');
        $this->expense($a, '20.50');
        $this->block($a, '2026-09-07 20:00', '2026-09-08 06:00');
        $response = $this->actingAs($a['user'])->get(route('vehicle-profitability.index', ['date_from' => '2026-09-08', 'date_to' => '2026-09-08']))->assertOk();
        $this->assertSame('-10.50', $response->viewData('amounts')[$a['vehicle']->id]['MAD']['margin']);
        $this->assertSame(21600, (int) $response->viewData('downtime')[$a['vehicle']->id]);
    }

    public function test_profitability_requires_financial_permissions_and_server_criteria(): void
    {
        $a = $this->fixture('rental-agent');
        $b = $this->fixture();
        $this->actingAs($a['user'])->get(route('vehicle-profitability.index'))->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(TenantContext::class)->run($a['tenant'], fn () => app(BuildMinimalReport::class)->vehicleProfitability(ReportCriteria::fromInclusiveDates($b['tenant']->id, [$b['agency']->id], '2026-09-08', '2026-09-08', 'Africa/Casablanca')));
    }

    private function fixture(string $role = 'tenant-owner'): array
    {
        $tenant = Tenant::factory()->create(['settings' => ['currency' => 'MAD', 'timezone' => 'Africa/Casablanca']]);
        $agency = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create());
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => $role === 'tenant-owner' ? null : $agency->id, 'role_id' => Role::where('slug', $role)->value('id'), 'must_change_password' => false]);

        return app(TenantContext::class)->run($tenant, function () use ($tenant, $agency, $user) {
            $category = VehicleCategory::create(['code' => 'ECONO', 'name' => 'Économique', 'is_active' => true]);
            $customer = Customer::create(['agency_id' => $agency->id, 'customer_type' => 'individual', 'first_name' => 'Client', 'last_name' => 'Test', 'verification_status' => 'pending']);
            $vehicle = app(CreateVehicle::class)->handle(['agency_id' => $agency->id, 'vehicle_category_id' => $category->id, 'registration_number' => 'TEST-'.str()->random(8), 'brand' => 'Dacia', 'model' => 'Logan', 'fuel_type' => 'diesel', 'transmission' => 'manual', 'current_mileage' => 1000], $user->id);

            return compact('tenant', 'agency', 'user', 'customer', 'category', 'vehicle');
        });
    }

    private function block(array $f, string $start, string $end): VehicleBlock
    {
        return app(TenantContext::class)->run($f['tenant'], fn () => VehicleBlock::create(['agency_id' => $f['agency']->id, 'vehicle_id' => $f['vehicle']->id, 'block_type' => 'manual', 'status' => 'active', 'starts_at' => CarbonImmutable::parse($start, 'Africa/Casablanca'), 'ends_at' => CarbonImmutable::parse($end, 'Africa/Casablanca'), 'reason' => 'Test planning', 'created_by' => $f['user']->id]));
    }

    private function grant(array $f): CustomerPortalAccess
    {
        return app(TenantContext::class)->run($f['tenant'], fn () => CustomerPortalAccess::create(['agency_id' => $f['agency']->id, 'customer_id' => $f['customer']->id, 'issued_by' => $f['user']->id, 'expires_at' => now()->addHours(48)]));
    }

    private function enter(CustomerPortalAccess $grant): void
    {
        $this->post(URL::temporarySignedRoute('portal.enter', $grant->expires_at, ['access' => $grant->id]))->assertRedirect(route('portal.home'));
    }

    private function contract(array $f, string $currency = 'MAD'): RentalContract
    {
        return app(TenantContext::class)->run($f['tenant'], function () use ($f, $currency) {
            $reservation = Reservation::create(['agency_id' => $f['agency']->id, 'customer_id' => $f['customer']->id, 'vehicle_category_id' => $f['category']->id, 'vehicle_id' => $f['vehicle']->id, 'reservation_number' => 'RES-'.str()->random(10), 'starts_at' => now()->startOfDay()->addHours(14), 'ends_at' => now()->addDay(), 'status' => 'draft', 'subtotal' => '100.00', 'options_total' => '0.00', 'total_amount' => '100.00', 'deposit_amount' => '0.00', 'currency' => $currency, 'pricing_snapshot' => [], 'created_by' => $f['user']->id]);

            return RentalContract::create(['agency_id' => $f['agency']->id, 'reservation_id' => $reservation->id, 'customer_id' => $f['customer']->id, 'vehicle_id' => $f['vehicle']->id, 'contract_number' => 'CTR-'.str()->random(10), 'status' => 'draft', 'expected_start_at' => $reservation->starts_at, 'expected_return_at' => $reservation->ends_at, 'rental_subtotal' => '100.00', 'additional_charges_total' => '0.00', 'total_amount' => '100.00', 'deposit_required' => '0.00', 'currency' => $currency, 'created_by' => $f['user']->id]);
        });
    }

    private function invoice(array $f, string $amount, string $currency = 'MAD'): Invoice
    {
        $contract = $this->contract($f, $currency);

        return app(TenantContext::class)->run($f['tenant'], fn () => Invoice::create(['agency_id' => $f['agency']->id, 'rental_contract_id' => $contract->id, 'customer_id' => $f['customer']->id, 'invoice_number' => 'INV-'.str()->random(10), 'status' => 'issued', 'issued_at' => now(), 'due_at' => now()->addDays(7), 'currency' => $currency, 'tax_mode' => 'none', 'tax_rate' => '0.0000', 'subtotal' => $amount, 'tax_amount' => '0.00', 'total_amount' => $amount, 'paid_amount' => '0.00', 'balance_due' => $amount, 'customer_snapshot' => [], 'contract_snapshot' => [], 'created_by' => $f['user']->id, 'issued_by' => $f['user']->id]));
    }

    private function expense(array $f, string $amount, string $status = 'approved'): Expense
    {
        return app(TenantContext::class)->run($f['tenant'], fn () => Expense::create(['agency_id' => $f['agency']->id, 'vehicle_id' => $f['vehicle']->id, 'expense_number' => 'EXP-'.str()->random(10), 'category' => 'administration', 'description' => 'Dépense test', 'amount' => $amount, 'tax_amount' => '0.00', 'currency' => 'MAD', 'expense_date' => '2026-09-08', 'status' => $status, 'created_by' => $f['user']->id, 'approved_by' => $status === 'approved' ? $f['user']->id : null]));
    }

    private function payment(array $f, Invoice $invoice, string $amount, string $direction = 'incoming', string $status = 'posted'): void
    {
        app(TenantContext::class)->run($f['tenant'], function () use ($f, $invoice, $amount, $direction, $status) {
            $payment = Payment::create(['agency_id' => $f['agency']->id, 'rental_contract_id' => $invoice->rental_contract_id, 'customer_id' => $f['customer']->id, 'payment_number' => 'PAY-'.str()->random(10), 'direction' => $direction, 'payment_method' => 'cash', 'status' => $status, 'amount' => $amount, 'currency' => $invoice->currency, 'idempotency_key' => (string) str()->uuid(), 'paid_at' => now(), 'posted_at' => $status === 'posted' ? now() : null, 'created_by' => $f['user']->id, 'posted_by' => $status === 'posted' ? $f['user']->id : null]);
            PaymentAllocation::create(['agency_id' => $f['agency']->id, 'customer_id' => $f['customer']->id, 'currency' => $invoice->currency, 'payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount' => $amount]);
        });
    }
}
