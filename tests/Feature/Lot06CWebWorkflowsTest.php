<?php

namespace Tests\Feature;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\CreateDriver;
use App\Actions\Documents\StorePrivateDocument;
use App\Actions\Finance\CreateInvoiceFromReturnedContract;
use App\Actions\Finance\IssueInvoice;
use App\Actions\Rentals\CreateRentalContractFromReservation;
use App\Actions\Reservations\ConfirmReservation;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Vehicles\CreateVehicle;
use App\Enums\CustomerType;
use App\Enums\DocumentType;
use App\Enums\RentalContractStatus;
use App\Enums\VehicleOperationalStatus;
use App\Enums\VerificationStatus;
use App\Models\Agency;
use App\Models\DepositTransaction;
use App\Models\InsuranceClaim;
use App\Models\InsuranceCompany;
use App\Models\InsurancePolicy;
use App\Models\Invoice;
use App\Models\MaintenanceOrder;
use App\Models\Payment;
use App\Models\PricingRule;
use App\Models\RentalContract;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VehicleCategory;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Lot06CWebWorkflowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('documents.disk'));
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_contract_and_finance_cycle_is_operable_from_web_routes(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['owner']);

        $this->post(route('contracts.store', $f['reservation']))->assertRedirect();
        $contract = $this->inTenant($f, fn () => RentalContract::where('reservation_id', $f['reservation']->id)->firstOrFail());
        $this->get(route('contracts.show', $contract))->assertOk()->assertSee('Prérequis du cycle')->assertSee('PDF de la version');

        $this->post(route('contracts.version-document.store', $contract), ['file' => $this->pdf('contrat-web.pdf')])->assertRedirect();
        $document = $this->inTenant($f, fn () => $contract->refresh()->currentVersion->document);
        $this->assertNotNull($document);
        $documentVersion = $this->inTenant($f, fn () => $document->versions()->latest('version_number')->firstOrFail());
        Storage::disk(config('documents.disk'))->assertExists($documentVersion->stored_path);
        $this->get(route('documents.show', $document))->assertOk()->assertSee('Document privé');
        $this->post(route('contracts.ready', $contract))->assertRedirect();
        $this->post(route('contracts.accept', $contract), ['accepted_by_name' => 'Signataire Web', 'acceptance_method' => 'typed_name'])->assertRedirect();

        $this->post(route('finance.deposits.receive', $contract), ['amount' => '300.00', 'idempotency_key' => 'web-deposit-receive'])->assertRedirect();
        $this->post(route('finance.deposits.receive', $contract), ['amount' => '10.00', 'idempotency_key' => 'web-deposit-extra'])->assertRedirect();
        $extraDeposit = $this->inTenant($f, fn () => DepositTransaction::where('idempotency_key', 'web-deposit-extra')->firstOrFail());
        $this->post(route('finance.deposits.reverse', $extraDeposit), ['idempotency_key' => 'web-deposit-extra-reverse', 'reason' => 'Erreur de saisie'])->assertRedirect();
        $this->assertSame('300.00', $this->inTenant($f, fn () => $contract->refresh()->deposit_received));
        $this->post(route('contracts.departure-inspection', $contract), ['mileage' => 1010, 'fuel_level' => '75.00', 'items' => $this->inspectionItems()])->assertRedirect();
        $this->post(route('contracts.activate', $contract))->assertRedirect();
        $this->post(route('contracts.return-inspection', $contract), ['mileage' => 1110, 'fuel_level' => '70.00', 'items' => $this->inspectionItems()])->assertRedirect();
        $this->post(route('contracts.returned', $contract), ['approved_charge_ids' => [], 'rejected_charge_ids' => [], 'reason' => 'Retour web'])->assertRedirect();
        $this->assertSame(RentalContractStatus::Returned, $this->inTenant($f, fn () => $contract->refresh()->status));

        $this->get(route('contracts.show', $contract))->assertOk()->assertSee('Créer la facture');
        $this->post(route('finance.invoices.create', $contract), ['tax_mode' => 'none', 'tax_rate' => '0.0000'])->assertRedirect();
        $invoice = $this->inTenant($f, fn () => Invoice::where('rental_contract_id', $contract->id)->firstOrFail());
        $this->get(route('finance.invoices.show', $invoice))->assertOk()->assertSee($invoice->invoice_number)->assertSee('Émettre');
        $this->post(route('finance.invoices.issue', $invoice))->assertRedirect();
        $this->get(route('finance.invoices.show', $invoice))->assertOk()->assertSee('Enregistrer un paiement');

        $this->post(route('finance.contracts.close', $contract))->assertSessionHasErrors('invoice');
        $paymentData = ['agency_id' => $f['agency']->id, 'rental_contract_id' => $contract->id, 'customer_id' => $f['customer']->id, 'payment_method' => 'cash', 'amount' => $invoice->total_amount, 'currency' => 'MAD', 'idempotency_key' => 'web-payment-idempotent'];
        $this->post(route('finance.payments.store'), [...$paymentData, 'amount' => '1e3'])->assertSessionHasErrors('amount');
        $this->post(route('finance.payments.store'), $paymentData)->assertRedirect();
        $this->post(route('finance.payments.store'), $paymentData)->assertRedirect();
        $payment = $this->inTenant($f, fn () => Payment::where('idempotency_key', 'web-payment-idempotent')->firstOrFail());
        $this->assertSame(1, $this->inTenant($f, fn () => Payment::where('idempotency_key', 'web-payment-idempotent')->count()));
        $this->post(route('finance.allocations.store', [$payment, $invoice]), ['amount' => $invoice->total_amount])->assertRedirect();
        $this->post(route('finance.payments.post', $payment))->assertRedirect();
        $this->assertSame('paid', $this->inTenant($f, fn () => $invoice->refresh()->status));
        $this->post(route('finance.contracts.close', $contract))->assertSessionHasErrors('deposit');
        $this->post(route('finance.deposits.refund', $contract), ['amount' => '300.00', 'idempotency_key' => 'web-deposit-refund', 'reason' => 'Solde restitué'])->assertRedirect();
        $this->post(route('finance.contracts.close', $contract))->assertRedirect();

        $closed = $this->inTenant($f, fn () => $contract->refresh());
        $this->assertSame(RentalContractStatus::Closed, $closed->status);
        $this->assertSame('0.00', $invoice->refresh()->balance_due);
        $this->assertFalse(is_float($invoice->total_amount));
        $this->assertFalse(is_float($closed->deposit_refunded));
        $this->get(route('documents.download', $document))->assertOk();
        auth()->logout();
        $this->get(route('documents.download', $document))->assertRedirect(route('login'));
        $this->get('/storage/contracts/'.$document->id)->assertNotFound();
    }

    public function test_sensitive_finance_buttons_are_hidden_and_direct_routes_return_403(): void
    {
        $f = $this->fixture();
        $contract = $this->returnedContract($f);
        $invoice = $this->inTenant($f, fn () => app(CreateInvoiceFromReturnedContract::class)->handle($contract, $f['owner']->id));
        $invoice = $this->inTenant($f, fn () => app(IssueInvoice::class)->handle($invoice, $f['owner']->id));
        $agent = User::factory()->create(['tenant_id' => $f['tenant']->id, 'agency_id' => $f['agency']->id, 'role_id' => Role::where('slug', 'rental-agent')->value('id')]);

        $this->actingAs($agent)->get(route('finance.invoices.show', $invoice))->assertOk()->assertDontSee('Enregistrer un paiement')->assertDontSee('Contrepasser')->assertDontSee('Clôturer le contrat');
        $this->post(route('finance.payments.store'), ['agency_id' => $f['agency']->id, 'customer_id' => $f['customer']->id, 'payment_method' => 'cash', 'amount' => '10.00', 'currency' => 'MAD', 'idempotency_key' => 'forbidden'])->assertForbidden();
        $this->post(route('finance.invoices.issue', $invoice))->assertForbidden();
        $this->post(route('finance.contracts.close', $contract))->assertForbidden();

        $otherTenant = Tenant::factory()->create();
        $otherAgency = app(TenantContext::class)->run($otherTenant, fn () => Agency::factory()->create());
        $foreignRole = Role::where('slug', 'tenant-owner')->firstOrFail();
        $foreignUser = User::factory()->create(['tenant_id' => $otherTenant->id, 'agency_id' => null, 'role_id' => $foreignRole->id]);
        $this->actingAs($foreignUser)->get(route('finance.invoices.show', $invoice))->assertNotFound();
        $this->assertSame($otherTenant->id, $otherAgency->tenant_id);
    }

    public function test_maintenance_web_cycle_normalizes_checkbox_and_hides_sql_conflicts(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['owner']);
        $start = CarbonImmutable::now()->addDays(10)->startOfHour();
        $this->post(route('maintenance.store'), $this->maintenanceData($f, $start, $start->addHours(2)))->assertRedirect();
        $order = $this->inTenant($f, fn () => MaintenanceOrder::latest('id')->firstOrFail());
        $this->get(route('maintenance.show', $order))->assertOk()->assertSee('Bloc véhicule')->assertSee('Aucun bloc');
        $this->post(route('maintenance.approve', $order))->assertRedirect();
        $this->post(route('maintenance.start', $order))->assertRedirect();
        $this->post(route('maintenance.complete', $order), ['actual_cost' => '1250.50', 'mileage' => 1250, 'next_due_date' => today()->addMonths(6)->toDateString(), 'next_due_mileage' => 11250, 'return_to_active' => '1', 'reason' => 'Terminaison navigateur'])->assertRedirect();
        $completed = $this->inTenant($f, fn () => $order->refresh());
        $this->assertSame('completed', $completed->status);
        $this->assertSame(VehicleOperationalStatus::Active, $this->inTenant($f, fn () => $f['vehicle']->refresh()->operational_status));
        $this->assertSame('1250.50', $completed->actual_cost);
        $this->assertDatabaseHas('expenses', ['maintenance_order_id' => $order->id, 'amount' => '1250.50']);
        $this->get(route('maintenance.show', $order))->assertOk()->assertSee('Dépense générée')->assertSee('1250.50');

        $conflict = $this->maintenanceData($f, $f['reservation']->starts_at->addHour(), $f['reservation']->ends_at->subHour());
        $this->post(route('maintenance.store'), $conflict)->assertRedirect();
        $conflictingOrder = $this->inTenant($f, fn () => MaintenanceOrder::latest('id')->firstOrFail());
        $response = $this->post(route('maintenance.approve', $conflictingOrder));
        $response->assertSessionHasErrors('schedule');
        $this->assertStringNotContainsString('SQLSTATE', session('errors')->first('schedule'));
        $this->assertSame('planned', $this->inTenant($f, fn () => $conflictingOrder->refresh()->status));
    }

    public function test_policy_coverage_claim_and_state_machine_are_operable_from_web(): void
    {
        $f = $this->fixture();
        $contract = $this->inTenant($f, fn () => app(CreateRentalContractFromReservation::class)->handle($f['reservation'], $f['owner']->id));
        $this->actingAs($f['owner']);
        $this->post(route('insurance.companies.store'), ['name' => 'Assureur Web', 'email' => 'web@example.test'])->assertRedirect();
        $company = $this->inTenant($f, fn () => InsuranceCompany::where('name', 'Assureur Web')->firstOrFail());
        $this->get(route('insurance.policies.create'))->assertOk()->assertSee('Créer une police');
        $this->post(route('insurance.policies.store'), ['agency_id' => $f['agency']->id, 'vehicle_id' => $f['vehicle']->id, 'insurance_company_id' => $company->id, 'policy_number' => 'POL-WEB-SECRET-1234', 'policy_type' => 'comprehensive', 'starts_at' => today()->toDateString(), 'ends_at' => today()->addYear()->toDateString(), 'premium_amount' => '5000.00', 'deductible_amount' => '2500.00', 'currency' => 'MAD'])->assertRedirect();
        $policy = $this->inTenant($f, fn () => InsurancePolicy::latest('id')->firstOrFail());
        $this->post(route('insurance.coverages.store', $policy), ['coverage_type' => 'collision', 'label' => 'Collision Web', 'limit_amount' => '50000.00', 'deductible_amount' => '2500.00'])->assertRedirect();
        $this->post(route('insurance.policies.documents.store', $policy), ['document_type' => DocumentType::InsurancePolicySigned->value, 'title' => 'Police signée Web', 'is_sensitive' => true, 'file' => $this->pdf('police-web.pdf')])->assertRedirect();
        $this->post(route('insurance.policies.activate', $policy))->assertRedirect();
        $this->get(route('insurance.policies.show', $policy))->assertOk()->assertSee('Collision Web')->assertSee($policy->maskedPolicyNumber())->assertDontSee('POL-WEB-SECRET-1234');

        $claimData = ['agency_id' => $f['agency']->id, 'insurance_policy_id' => $policy->id, 'rental_contract_id' => $contract->id, 'incident_at' => now()->toDateTimeString(), 'claimed_amount' => '7000.00', 'insurer_reference' => 'REF-WEB-SENSIBLE', 'notes' => 'Déclaration humaine'];
        $this->post(route('insurance.claims.store'), [...$claimData, 'status' => 'approved'])->assertSessionHasErrors('status');
        $this->post(route('insurance.claims.store'), $claimData)->assertRedirect();
        $claim = $this->inTenant($f, fn () => InsuranceClaim::latest('id')->firstOrFail());
        $this->assertSame('reported', $claim->status->value);
        $this->get(route('insurance.claims.show', $claim))->assertOk()->assertSee('Chronologie immuable')->assertDontSee('REF-WEB-SENSIBLE');
        $this->post(route('insurance.claims.approve', $claim), ['approved_amount' => '4500.00'])->assertSessionHasErrors('status');
        $this->post(route('insurance.claims.submit', $claim), ['note' => 'Soumission'])->assertRedirect();
        $this->post(route('insurance.claims.review', $claim), ['note' => 'Revue humaine'])->assertRedirect();
        $this->post(route('insurance.claims.approve', $claim), ['approved_amount' => '4500.00', 'note' => 'Décision humaine'])->assertRedirect();
        $this->post(route('insurance.claims.settle', $claim), ['settled_amount' => '4000.00', 'note' => 'Règlement assureur'])->assertRedirect();
        $this->post(route('insurance.claims.documents.store', $claim), ['document_type' => DocumentType::InsuranceClaimSettlementProof->value, 'title' => 'Preuve de règlement Web', 'is_sensitive' => true, 'file' => $this->pdf('reglement-web.pdf')])->assertRedirect();
        $this->post(route('insurance.claims.close', $claim), ['note' => 'Dossier terminé'])->assertRedirect();
        $closed = $this->inTenant($f, fn () => $claim->refresh());
        $this->assertSame('closed', $closed->status->value);
        $this->assertSame('4500.00', $closed->approved_amount);
        $this->assertSame('4000.00', $closed->settled_amount);
        $this->assertFalse(is_float($closed->settled_amount));
        $this->assertSame(6, $this->inTenant($f, fn () => $closed->statusHistories()->count()));
    }

    public function test_agency_bound_writes_are_forbidden_from_forged_web_requests(): void
    {
        $f = $this->fixture();
        $otherAgency = $this->inTenant($f, fn () => Agency::factory()->create());
        $otherVehicle = app(TenantContext::class)->run($f['tenant'], fn () => app(CreateVehicle::class)->handle(['agency_id' => $otherAgency->id, 'vehicle_category_id' => $f['category']->id, 'registration_number' => 'OTHER-'.uniqid(), 'brand' => 'Dacia', 'model' => 'Sandero', 'production_year' => 2025, 'fuel_type' => 'diesel', 'transmission' => 'manual', 'current_mileage' => 0], $f['owner']->id), $otherAgency->id);
        $manager = User::factory()->create(['tenant_id' => $f['tenant']->id, 'agency_id' => $f['agency']->id, 'role_id' => Role::where('slug', 'agency-manager')->value('id')]);
        $start = CarbonImmutable::now()->addDays(20)->startOfHour();

        $this->actingAs($manager)->post(route('maintenance.store'), [...$this->maintenanceData($f, $start, $start->addHour()), 'agency_id' => $otherAgency->id, 'vehicle_id' => $otherVehicle->id])->assertForbidden();
        $company = $this->inTenant($f, fn () => InsuranceCompany::create(['name' => 'Assureur Isolation', 'is_active' => true]));
        $this->post(route('insurance.policies.store'), ['agency_id' => $otherAgency->id, 'vehicle_id' => $otherVehicle->id, 'insurance_company_id' => $company->id, 'policy_number' => 'FORGED', 'policy_type' => 'other', 'starts_at' => today()->toDateString(), 'ends_at' => today()->addYear()->toDateString(), 'premium_amount' => '1.00', 'deductible_amount' => '0.00', 'currency' => 'MAD', 'status' => 'active'])->assertForbidden();
        $this->assertSame(0, $this->inTenant($f, fn () => MaintenanceOrder::where('agency_id', $otherAgency->id)->count()));
        $this->assertSame(0, $this->inTenant($f, fn () => InsurancePolicy::where('agency_id', $otherAgency->id)->count()));
    }

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create(['name' => 'Tenant Web 06C']);
        $agency = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create(['name' => 'Agence Web 06C']));
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => null, 'role_id' => Role::where('slug', 'tenant-owner')->value('id')]);
        $f = compact('tenant', 'agency', 'owner');

        return $this->inTenant($f, function () use ($f) {
            $category = VehicleCategory::create(['code' => 'W'.uniqid(), 'name' => 'Web 06C', 'is_active' => true]);
            $vehicle = app(CreateVehicle::class)->handle(['agency_id' => $f['agency']->id, 'vehicle_category_id' => $category->id, 'registration_number' => 'WEB-'.uniqid(), 'brand' => 'Dacia', 'model' => 'Logan', 'production_year' => 2025, 'fuel_type' => 'diesel', 'transmission' => 'manual', 'current_mileage' => 1000], $f['owner']->id);
            $customer = app(CreateCustomer::class)->handle(['agency_id' => $f['agency']->id, 'customer_type' => CustomerType::Individual, 'first_name' => 'Client', 'last_name' => 'Web', 'verification_status' => VerificationStatus::Verified]);
            $driver = app(CreateDriver::class)->handle($customer, ['first_name' => 'Conducteur', 'last_name' => 'Web', 'licence_number' => 'LIC-WEB-'.uniqid(), 'licence_expires_at' => today()->addYears(2), 'verification_status' => VerificationStatus::Verified, 'is_primary' => true]);
            PricingRule::create(['agency_id' => null, 'vehicle_category_id' => $category->id, 'name' => 'Tarif Web', 'daily_rate' => '400.00', 'deposit_amount' => '300.00', 'included_km_per_day' => 200, 'extra_km_rate' => '2.50', 'late_hour_rate' => '75.00', 'minimum_days' => 1, 'maximum_days' => 30, 'valid_from' => today()->subYear(), 'priority' => 0, 'currency' => 'MAD', 'conditions' => [], 'is_active' => true, 'created_by' => $f['owner']->id]);
            $startsAt = CarbonImmutable::now()->addDays(2)->startOfHour();
            $reservation = app(CreateReservation::class)->handle(['agency_id' => $f['agency']->id, 'customer_id' => $customer->id, 'driver_id' => $driver->id, 'vehicle_category_id' => $category->id, 'vehicle_id' => $vehicle->id, 'starts_at' => $startsAt, 'ends_at' => $startsAt->addDay(), 'status' => 'draft'], $f['owner']->id);
            app(ConfirmReservation::class)->handle($reservation, $f['owner']->id);
            app(StorePrivateDocument::class)->handle($customer, ['document_type' => DocumentType::CustomerIdentity, 'title' => 'Identité fictive', 'is_sensitive' => true], $this->pdf('identite-web.pdf'), $f['owner']->id);
            app(StorePrivateDocument::class)->handle($driver, ['document_type' => DocumentType::DrivingLicence, 'title' => 'Permis fictif', 'is_sensitive' => true], $this->pdf('permis-web.pdf'), $f['owner']->id);

            return [...$f, 'category' => $category, 'vehicle' => $vehicle, 'customer' => $customer, 'driver' => $driver, 'reservation' => $reservation->refresh()];
        });
    }

    private function returnedContract(array $f): RentalContract
    {
        return $this->inTenant($f, function () use ($f) {
            $contract = app(CreateRentalContractFromReservation::class)->handle($f['reservation'], $f['owner']->id);
            $contract->forceFill(['status' => RentalContractStatus::Returned, 'returned_at' => now()])->save();

            return $contract;
        });
    }

    private function maintenanceData(array $f, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return ['agency_id' => $f['agency']->id, 'vehicle_id' => $f['vehicle']->id, 'maintenance_type' => 'preventive', 'priority' => 'normal', 'title' => 'Maintenance Web', 'scheduled_start_at' => $start->toIso8601String(), 'scheduled_end_at' => $end->toIso8601String(), 'estimated_cost' => '1000.00'];
    }

    private function inspectionItems(): array
    {
        return [['item_code' => 'body', 'label' => 'Carrosserie', 'condition' => 'good'], ['item_code' => 'interior', 'label' => 'Habitacle', 'condition' => 'good']];
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\nDocument strictement fictif\n%%EOF");
    }

    private function inTenant(array $fixture, callable $callback): mixed
    {
        return app(TenantContext::class)->run($fixture['tenant'], $callback, $fixture['agency']->id);
    }
}
