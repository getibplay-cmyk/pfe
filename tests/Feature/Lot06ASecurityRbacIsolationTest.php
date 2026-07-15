<?php

namespace Tests\Feature;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Finance\AllocatePaymentToInvoice;
use App\Actions\Insurance\CreateInsuranceClaim;
use App\Actions\Rentals\GenerateBusinessNumber;
use App\Actions\Vehicles\CreateVehicle;
use App\Enums\CustomerType;
use App\Enums\VerificationStatus;
use App\Models\Agency;
use App\Models\DamageReport;
use App\Models\InsuranceCompany;
use App\Models\InsurancePolicy;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\RentalContract;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoTenancySeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class Lot06ASecurityRbacIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_demo_seeding_is_refused_when_the_seeder_is_called_directly_in_production(): void
    {
        $previousEnvironment = app()->environment();
        $refused = false;

        try {
            $this->app->detectEnvironment(fn () => 'production');
            app(DemoTenancySeeder::class)->run();
        } catch (LogicException) {
            $refused = true;
        } finally {
            $this->app->detectEnvironment(fn () => $previousEnvironment);
        }

        $this->assertTrue($refused);
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_financial_permission_matrix_enforces_separation_of_duties_for_every_role(): void
    {
        $permissions = [
            'invoice.view', 'invoice.create', 'invoice.issue', 'invoice.void',
            'payment.view', 'payment.create', 'payment.post', 'payment.allocate', 'payment.reverse',
            'deposit.view', 'deposit.create', 'deposit.reverse',
            'expense.view', 'expense.create', 'expense.approve', 'contract.close',
        ];
        $allowed = [
            'tenant-owner' => $permissions,
            'agency-manager' => ['invoice.view', 'payment.view', 'deposit.view', 'expense.view'],
            'rental-agent' => ['invoice.view', 'payment.view', 'deposit.view'],
            'fleet-manager' => ['expense.view'],
            'accountant' => $permissions,
            'viewer-auditor' => ['invoice.view', 'payment.view', 'deposit.view', 'expense.view'],
        ];

        foreach ($allowed as $roleSlug => $expectedPermissions) {
            $role = Role::where('slug', $roleSlug)->firstOrFail();
            $actualPermissions = $role->permissions()->whereIn('slug', $permissions)->pluck('slug')->sort()->values()->all();
            sort($expectedPermissions);

            $this->assertSame($expectedPermissions, $actualPermissions, $roleSlug);
        }
    }

    public function test_agency_bound_user_cannot_create_move_or_mutate_resources_outside_their_agency(): void
    {
        $tenant = Tenant::factory()->create();
        $context = app(TenantContext::class);
        [$agency, $otherAgency] = $context->run($tenant, fn () => [Agency::factory()->create(), Agency::factory()->create()]);
        $manager = User::factory()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'role_id' => Role::where('slug', 'agency-manager')->value('id'),
        ]);

        [$category, $vehicle, $customer, $foreignCustomer] = $context->run($tenant, function () use ($agency, $otherAgency, $manager) {
            $category = VehicleCategory::create(['code' => 'L06A', 'name' => 'Lot 06A', 'is_active' => true]);
            $vehicle = app(CreateVehicle::class)->handle($this->vehicleData($agency, $category, 'L06A-OWN'), $manager->id);
            $customer = app(CreateCustomer::class)->handle($this->customerData($agency, 'Autorisé'));
            $foreignCustomer = app(CreateCustomer::class)->handle($this->customerData($otherAgency, 'Autre agence'));

            return [$category, $vehicle, $customer, $foreignCustomer];
        });

        $this->actingAs($manager)->get(route('vehicles.create'))
            ->assertOk()->assertSee($agency->name)->assertDontSee($otherAgency->name);
        $this->actingAs($manager)->get(route('customers.create'))
            ->assertOk()->assertSee($agency->name)->assertDontSee($otherAgency->name);

        $this->actingAs($manager)->post(route('vehicles.store'), $this->vehicleData($otherAgency, $category, 'L06A-FORBIDDEN'))
            ->assertSessionHasErrors('agency_id');
        $this->actingAs($manager)->put(route('vehicles.update', $vehicle), $this->vehicleData($otherAgency, $category, $vehicle->registration_number))
            ->assertSessionHasErrors('agency_id');
        $this->actingAs($manager)->post(route('customers.store'), $this->customerData($otherAgency, 'Interdit'))
            ->assertSessionHasErrors('agency_id');
        $this->actingAs($manager)->put(route('customers.update', $customer), $this->customerData($otherAgency, 'Déplacement interdit'))
            ->assertSessionHasErrors('agency_id');
        $this->actingAs($manager)->post(route('customers.drivers.store', $foreignCustomer), $this->driverData())
            ->assertForbidden();

        $this->assertDatabaseMissing('vehicles', ['tenant_id' => $tenant->id, 'registration_number' => 'L06A-FORBIDDEN']);
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'agency_id' => $agency->id]);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'agency_id' => $agency->id]);
        $this->assertDatabaseCount('drivers', 0);
    }

    public function test_postgresql_rejects_customer_with_an_agency_from_another_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $foreignAgency = app(TenantContext::class)->run($foreignTenant, fn () => Agency::factory()->create());

        $this->assertConstraintViolation(function () use ($tenant, $foreignAgency) {
            DB::table('customers')->insert([
                'tenant_id' => $tenant->id,
                'agency_id' => $foreignAgency->id,
                'customer_type' => 'individual',
                'first_name' => 'Cross',
                'last_name' => 'Tenant',
                'verification_status' => 'pending',
                'custom_values' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_claims_and_allocations_are_rejected_when_relational_scopes_do_not_match(): void
    {
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $tenant = Tenant::where('slug', 'atlas-location-demo')->firstOrFail();
        $context = app(TenantContext::class);

        $fixtures = $context->run($tenant, function () {
            $owner = User::whereHas('role', fn ($query) => $query->where('slug', 'tenant-owner'))->firstOrFail();
            $damage = DamageReport::with('rentalContract')->firstOrFail();
            $contract = $damage->rentalContract;
            $company = InsuranceCompany::create(['name' => 'Assureur Lot 06A', 'is_active' => true]);
            $validPolicy = $this->policy($company, $contract->vehicle, 'L06A-VALID');
            $otherVehicle = Vehicle::where('agency_id', '!=', $contract->agency_id)->firstOrFail();
            $otherPolicy = $this->policy($company, $otherVehicle, 'L06A-OTHER');
            $invoice = Invoice::whereIn('status', ['issued', 'partially_paid'])->firstOrFail();
            $otherAgency = Agency::where('id', '!=', $invoice->agency_id)->firstOrFail();
            $payment = Payment::create([
                'agency_id' => $otherAgency->id,
                'customer_id' => $invoice->customer_id,
                'payment_number' => app(GenerateBusinessNumber::class)->handle('payment'),
                'direction' => 'incoming',
                'payment_method' => 'cash',
                'status' => 'pending',
                'amount' => '50.00',
                'currency' => $invoice->currency,
                'idempotency_key' => 'lot06a-cross-agency',
                'paid_at' => now(),
                'created_by' => $owner->id,
            ]);

            return compact('owner', 'damage', 'contract', 'validPolicy', 'otherPolicy', 'invoice', 'payment');
        });

        $validClaim = $context->run($tenant, fn () => app(CreateInsuranceClaim::class)->handle($this->claimData(
            $fixtures['validPolicy'],
            $fixtures['contract'],
            $fixtures['damage'],
        ), $fixtures['owner']->id));
        $this->assertSame($fixtures['contract']->agency_id, $validClaim->agency_id);

        $this->expectValidation(
            fn () => $context->run($tenant, fn () => app(CreateInsuranceClaim::class)->handle($this->claimData(
                $fixtures['otherPolicy'],
                $fixtures['contract'],
                $fixtures['damage'],
            ), $fixtures['owner']->id)),
            'rental_contract_id',
        );
        $this->expectValidation(
            fn () => $context->run($tenant, fn () => app(AllocatePaymentToInvoice::class)->handle($fixtures['payment'], $fixtures['invoice'], '25.00')),
            'allocation',
        );

        $this->assertConstraintViolation(function () use ($tenant, $fixtures) {
            DB::table('insurance_claims')->insert([
                'tenant_id' => $tenant->id,
                'agency_id' => $fixtures['otherPolicy']->agency_id,
                'insurance_policy_id' => $fixtures['otherPolicy']->id,
                'damage_report_id' => $fixtures['damage']->id,
                'rental_contract_id' => $fixtures['contract']->id,
                'claim_number' => 'CLM-L06A-INVALID',
                'status' => 'reported',
                'reported_at' => now(),
                'claimed_amount' => '100.00',
                'created_by' => $fixtures['owner']->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
        $this->assertConstraintViolation(function () use ($tenant, $fixtures) {
            DB::table('payment_allocations')->insert([
                'tenant_id' => $tenant->id,
                'agency_id' => $fixtures['payment']->agency_id,
                'customer_id' => $fixtures['payment']->customer_id,
                'currency' => $fixtures['payment']->currency,
                'payment_id' => $fixtures['payment']->id,
                'invoice_id' => $fixtures['invoice']->id,
                'amount' => '25.00',
                'created_at' => now(),
            ]);
        });

        $allocation = PaymentAllocation::withoutGlobalScopes()->firstOrFail();
        $this->assertNotNull($allocation->agency_id);
        $this->assertNotNull($allocation->customer_id);
        $this->assertSame('MAD', trim($allocation->currency));
    }

    private function vehicleData(Agency $agency, VehicleCategory $category, string $registration): array
    {
        return ['agency_id' => $agency->id, 'vehicle_category_id' => $category->id, 'registration_number' => $registration, 'brand' => 'Dacia', 'model' => 'Logan', 'production_year' => 2025, 'fuel_type' => 'diesel', 'transmission' => 'manual', 'current_mileage' => 1000];
    }

    private function customerData(Agency $agency, string $lastName): array
    {
        return ['agency_id' => $agency->id, 'customer_type' => CustomerType::Individual->value, 'first_name' => 'Client', 'last_name' => $lastName, 'verification_status' => VerificationStatus::Pending->value];
    }

    private function driverData(): array
    {
        return ['first_name' => 'Conducteur', 'last_name' => 'Interdit', 'licence_number' => 'L06A-LICENCE', 'licence_expires_at' => today()->addYear()->toDateString(), 'verification_status' => VerificationStatus::Pending->value, 'is_primary' => '0'];
    }

    private function policy(InsuranceCompany $company, Vehicle $vehicle, string $number): InsurancePolicy
    {
        $policy = new InsurancePolicy([
            'agency_id' => $vehicle->agency_id,
            'vehicle_id' => $vehicle->id,
            'insurance_company_id' => $company->id,
            'policy_type' => 'comprehensive',
            'starts_at' => today()->subMonth(),
            'ends_at' => today()->addYear(),
            'premium_amount' => '1000.00',
            'deductible_amount' => '500.00',
            'currency' => 'MAD',
            'status' => 'active',
        ]);
        $policy->setPolicyNumber($number)->save();

        return $policy;
    }

    private function claimData(InsurancePolicy $policy, RentalContract $contract, DamageReport $damage): array
    {
        return ['agency_id' => $policy->agency_id, 'insurance_policy_id' => $policy->id, 'damage_report_id' => $damage->id, 'rental_contract_id' => $contract->id, 'status' => 'reported', 'reported_at' => now(), 'claimed_amount' => '100.00'];
    }

    private function expectValidation(callable $callback, string $key): void
    {
        try {
            $callback();
            $this->fail('Validation attendue pour '.$key.'.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }

    private function assertConstraintViolation(callable $callback): void
    {
        try {
            DB::transaction($callback);
            $this->fail('La contrainte PostgreSQL devait refuser cette écriture.');
        } catch (QueryException $exception) {
            $this->assertContains($exception->getCode(), ['23503', '23514']);
        }
    }
}
