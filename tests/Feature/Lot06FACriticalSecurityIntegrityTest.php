<?php

namespace Tests\Feature;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\CreateDriver;
use App\Actions\Finance\CreateExpense;
use App\Actions\Finance\PostPayment;
use App\Actions\Finance\RecordDepositReceipt;
use App\Actions\Finance\RecordPayment;
use App\Actions\Finance\RefundDeposit;
use App\Actions\Finance\RetainDeposit;
use App\Actions\Finance\ReverseDepositTransaction;
use App\Actions\Finance\ReversePayment;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Vehicles\CreateVehicle;
use App\Enums\CustomerType;
use App\Enums\TenantStatus;
use App\Enums\VerificationStatus;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\DepositTransaction;
use App\Models\MaintenanceOrder;
use App\Models\Payment;
use App\Models\RentalContract;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Lot06FACriticalSecurityIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_payment_idempotency_accepts_only_the_exact_same_business_operation(): void
    {
        $f = $this->fixture();
        $data = $this->paymentData($f, $f['contract'], 'strict-payment');

        $payment = $this->inTenant($f, fn () => app(RecordPayment::class)->handle($data, $f['owner']->id));
        $retry = $this->inTenant($f, fn () => app(RecordPayment::class)->handle($data, $f['owner']->id));

        $this->assertSame($payment->id, $retry->id);
        $this->assertSame(1, Payment::withoutGlobalScopes()->where('idempotency_key', 'strict-payment')->count());

        foreach ([
            [...$data, 'amount' => '101.00'],
            [...$data, 'rental_contract_id' => $f['otherContract']->id],
            [...$data, 'payment_method' => 'bank_transfer'],
            [...$data, 'currency' => 'EUR'],
            [...$data, 'external_reference' => 'OTHER-REFERENCE'],
        ] as $conflict) {
            $this->expectValidation(
                fn () => $this->inTenant($f, fn () => app(RecordPayment::class)->handle($conflict, $f['owner']->id)),
                'idempotency_key',
            );
        }

        $this->assertSame(1, Payment::withoutGlobalScopes()->where('idempotency_key', 'strict-payment')->count());
        $this->assertSame('100.00', $payment->refresh()->amount);
    }

    public function test_deposit_and_reversal_idempotency_rejects_other_amount_type_contract_or_original(): void
    {
        $f = $this->fixture();
        $received = $this->inTenant($f, fn () => app(RecordDepositReceipt::class)->handle($f['contract'], '300.00', 'strict-deposit', $f['owner']->id));
        $retry = $this->inTenant($f, fn () => app(RecordDepositReceipt::class)->handle($f['contract'], '300.00', 'strict-deposit', $f['owner']->id));
        $this->assertSame($received->id, $retry->id);

        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(RecordDepositReceipt::class)->handle($f['contract'], '200.00', 'strict-deposit', $f['owner']->id)), 'idempotency_key');
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(RecordDepositReceipt::class)->handle($f['otherContract'], '300.00', 'strict-deposit', $f['owner']->id)), 'idempotency_key');
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(RefundDeposit::class)->handle($f['contract'], '300.00', 'strict-deposit', $f['owner']->id)), 'idempotency_key');

        $retained = $this->inTenant($f, fn () => app(RetainDeposit::class)->handle($f['contract'], '50.00', 'strict-retain', 'Frais validé', $f['owner']->id));
        $retainedRetry = $this->inTenant($f, fn () => app(RetainDeposit::class)->handle($f['contract'], '50.00', 'strict-retain', 'Frais validé', $f['owner']->id));
        $this->assertSame($retained->id, $retainedRetry->id);
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(RetainDeposit::class)->handle($f['contract'], '51.00', 'strict-retain', 'Frais validé', $f['owner']->id)), 'idempotency_key');

        $refunded = $this->inTenant($f, fn () => app(RefundDeposit::class)->handle($f['contract'], '25.00', 'strict-refund', $f['owner']->id, 'Solde rendu'));
        $refundedRetry = $this->inTenant($f, fn () => app(RefundDeposit::class)->handle($f['contract'], '25.00', 'strict-refund', $f['owner']->id, 'Solde rendu'));
        $this->assertSame($refunded->id, $refundedRetry->id);
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(RefundDeposit::class)->handle($f['contract'], '25.00', 'strict-refund', $f['owner']->id, 'Autre motif')), 'idempotency_key');

        $firstToReverse = $this->inTenant($f, fn () => app(RecordDepositReceipt::class)->handle($f['otherContract'], '30.00', 'deposit-to-reverse-1', $f['owner']->id));
        $otherReceived = $this->inTenant($f, fn () => app(RecordDepositReceipt::class)->handle($f['otherContract'], '25.00', 'deposit-to-reverse-2', $f['owner']->id));
        $reversal = $this->inTenant($f, fn () => app(ReverseDepositTransaction::class)->handle($firstToReverse, 'strict-deposit-reversal', 'Correction', $f['owner']->id));
        $reversalRetry = $this->inTenant($f, fn () => app(ReverseDepositTransaction::class)->handle($firstToReverse, 'strict-deposit-reversal', 'Correction', $f['owner']->id));
        $this->assertSame($reversal->id, $reversalRetry->id);
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(ReverseDepositTransaction::class)->handle($otherReceived, 'strict-deposit-reversal', 'Correction', $f['owner']->id)), 'idempotency_key');

        $this->assertSame(1, DepositTransaction::withoutGlobalScopes()->where('idempotency_key', 'strict-deposit')->count());
        $this->assertSame(1, DepositTransaction::withoutGlobalScopes()->where('idempotency_key', 'strict-deposit-reversal')->count());
    }

    public function test_payment_reversal_key_cannot_reverse_another_payment(): void
    {
        $f = $this->fixture();
        $first = $this->inTenant($f, fn () => app(RecordPayment::class)->handle($this->paymentData($f, $f['contract'], 'payment-original-1'), $f['owner']->id));
        $second = $this->inTenant($f, fn () => app(RecordPayment::class)->handle($this->paymentData($f, $f['contract'], 'payment-original-2'), $f['owner']->id));
        $this->inTenant($f, fn () => app(PostPayment::class)->handle($first, $f['owner']->id));
        $this->inTenant($f, fn () => app(PostPayment::class)->handle($second, $f['owner']->id));

        $reversal = $this->inTenant($f, fn () => app(ReversePayment::class)->handle($first, 'strict-payment-reversal', 'Correction', $f['owner']->id));
        $retry = $this->inTenant($f, fn () => app(ReversePayment::class)->handle($first, 'strict-payment-reversal', 'Correction', $f['owner']->id));
        $this->assertSame($reversal->id, $retry->id);
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(ReversePayment::class)->handle($second, 'strict-payment-reversal', 'Correction', $f['owner']->id)), 'idempotency_key');

        $this->assertSame('posted', $second->refresh()->status);
        $this->assertSame(1, Payment::withoutGlobalScopes()->where('idempotency_key', 'strict-payment-reversal')->count());
    }

    public function test_reservations_reject_cross_agency_customer_vehicle_driver_and_direct_sql(): void
    {
        $f = $this->fixture();
        $base = $this->reservationData($f, $f['agency'], $f['customer'], $f['driver'], $f['vehicle']);

        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(CreateReservation::class)->handle([...$base, 'customer_id' => $f['otherCustomer']->id], $f['owner']->id)), 'customer_id');
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(CreateReservation::class)->handle([...$base, 'vehicle_id' => $f['otherVehicle']->id], $f['owner']->id)), 'vehicle_id');
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(CreateReservation::class)->handle([...$base, 'driver_id' => $f['otherDriver']->id], $f['owner']->id)), 'driver_id');

        $response = $this->actingAs($f['manager'])->post(route('reservations.store'), [...$base, 'customer_id' => $f['otherCustomer']->id]);
        $response->assertSessionHasErrors('customer_id');
        $response->assertStatus(302);

        $this->assertConstraintViolation(fn () => DB::table('reservations')->insert($this->rawReservation($f, [
            'agency_id' => $f['agency']->id,
            'customer_id' => $f['otherCustomer']->id,
            'driver_id' => null,
            'vehicle_id' => $f['vehicle']->id,
        ])));
    }

    public function test_expenses_and_maintenance_reject_cross_agency_relations_in_http_action_and_postgresql(): void
    {
        $f = $this->fixture();
        $expense = $this->expenseData($f);

        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(CreateExpense::class)->handle([...$expense, 'rental_contract_id' => $f['otherContract']->id], $f['owner']->id)), 'rental_contract_id');
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(CreateExpense::class)->handle([...$expense, 'vehicle_id' => $f['otherVehicle']->id], $f['owner']->id)), 'vehicle_id');

        $foreignMaintenance = $this->inTenant($f, fn () => MaintenanceOrder::create([
            'agency_id' => $f['otherAgency']->id,
            'vehicle_id' => $f['otherVehicle']->id,
            'maintenance_number' => 'MNT-06FA-FOREIGN',
            'maintenance_type' => 'repair',
            'priority' => 'normal',
            'status' => 'planned',
            'title' => 'Autre agence',
            'estimated_cost' => '0.00',
            'actual_cost' => '0.00',
            'created_by' => $f['owner']->id,
        ]));
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(CreateExpense::class)->handle([...$expense, 'maintenance_order_id' => $foreignMaintenance->id], $f['owner']->id)), 'maintenance_order_id');

        $this->actingAs($f['manager'])->post(route('maintenance.store'), [
            'agency_id' => $f['agency']->id,
            'vehicle_id' => $f['otherVehicle']->id,
            'maintenance_type' => 'repair',
            'priority' => 'normal',
            'title' => 'Requête forgée',
            'estimated_cost' => '0.00',
        ])->assertSessionHasErrors('vehicle_id');

        $this->assertConstraintViolation(fn () => DB::table('expenses')->insert($this->rawExpense($f, [
            'agency_id' => $f['agency']->id,
            'rental_contract_id' => $f['otherContract']->id,
        ])));
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_reservation_form_resources_are_filtered_by_server_selected_agency(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['owner'])
            ->get(route('reservations.create', ['agency_id' => $f['agency']->id]))
            ->assertOk()
            ->assertSee($f['customer']->displayName())
            ->assertSee($f['vehicle']->registration_number)
            ->assertDontSee($f['otherCustomer']->displayName())
            ->assertDontSee($f['otherVehicle']->registration_number);
    }

    public function test_active_account_guard_blocks_inactive_tenant_agency_and_user_but_keeps_platform_profile_and_logout(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['manager'])->get(route('profile.edit'))->assertOk();

        $f['tenant']->forceFill(['status' => TenantStatus::Suspended])->save();
        $this->actingAs($f['manager'])->get(route('profile.edit'))->assertForbidden();
        $this->actingAs($f['manager'])->put(route('password.update'), [])->assertForbidden();

        $f['tenant']->forceFill(['status' => TenantStatus::Active])->save();
        $f['agency']->forceFill(['is_active' => false])->save();
        $this->actingAs($f['manager'])->get(route('profile.edit'))->assertForbidden();

        $f['agency']->forceFill(['is_active' => true])->save();
        $f['manager']->forceFill(['is_active' => false])->save();
        $this->actingAs($f['manager'])->get(route('profile.edit'))->assertForbidden();

        $platformAdmin = User::factory()->create(['tenant_id' => null, 'agency_id' => null, 'role_id' => null, 'is_platform_admin' => true, 'is_active' => true]);
        $this->actingAs($platformAdmin)->get(route('profile.edit'))->assertOk();

        $this->actingAs($f['manager'])->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
    }

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create();
        $context = app(TenantContext::class);
        [$agency, $otherAgency] = $context->run($tenant, fn () => [Agency::factory()->create(), Agency::factory()->create()]);
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => null, 'role_id' => Role::where('slug', 'tenant-owner')->value('id')]);
        $manager = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => $agency->id, 'role_id' => Role::where('slug', 'agency-manager')->value('id')]);

        return $context->run($tenant, function () use ($tenant, $agency, $otherAgency, $owner, $manager) {
            $category = VehicleCategory::create(['code' => 'L06FA', 'name' => 'Lot 06F-A', 'is_active' => true]);
            $vehicle = app(CreateVehicle::class)->handle($this->vehicleData($agency, $category, 'L06FA-OWN'), $owner->id);
            $otherVehicle = app(CreateVehicle::class)->handle($this->vehicleData($otherAgency, $category, 'L06FA-OTHER'), $owner->id);
            $customer = app(CreateCustomer::class)->handle($this->customerData($agency, 'Principal'));
            $otherCustomer = app(CreateCustomer::class)->handle($this->customerData($otherAgency, 'Autre agence'));
            $secondCustomer = app(CreateCustomer::class)->handle($this->customerData($agency, 'Autre client'));
            $driver = app(CreateDriver::class)->handle($customer, $this->driverData('OWN'));
            $otherDriver = app(CreateDriver::class)->handle($secondCustomer, $this->driverData('OTHER'));
            $reservation = app(CreateReservation::class)->handle($this->reservationData(compact('agency', 'customer', 'driver', 'vehicle', 'category'), $agency, $customer, $driver, $vehicle), $owner->id);
            $otherReservation = app(CreateReservation::class)->handle($this->reservationData(compact('agency', 'customer', 'driver', 'vehicle', 'category'), $otherAgency, $otherCustomer, null, $otherVehicle), $owner->id);
            $contract = $this->contract($reservation, $owner, 'CTR-06FA-001');
            $otherContract = $this->contract($otherReservation, $owner, 'CTR-06FA-002');

            return compact('tenant', 'agency', 'otherAgency', 'owner', 'manager', 'category', 'vehicle', 'otherVehicle', 'customer', 'otherCustomer', 'secondCustomer', 'driver', 'otherDriver', 'contract', 'otherContract');
        });
    }

    private function contract($reservation, User $owner, string $number): RentalContract
    {
        return RentalContract::create([
            'agency_id' => $reservation->agency_id,
            'reservation_id' => $reservation->id,
            'customer_id' => $reservation->customer_id,
            'vehicle_id' => $reservation->vehicle_id,
            'contract_number' => $number,
            'status' => 'draft',
            'expected_start_at' => $reservation->starts_at,
            'expected_return_at' => $reservation->ends_at,
            'rental_subtotal' => '100.00',
            'total_amount' => '100.00',
            'deposit_required' => '300.00',
            'currency' => 'MAD',
            'created_by' => $owner->id,
        ]);
    }

    private function reservationData(array $f, Agency $agency, Customer $customer, $driver, Vehicle $vehicle): array
    {
        return [
            'agency_id' => $agency->id,
            'customer_id' => $customer->id,
            'driver_id' => $driver?->id,
            'vehicle_category_id' => $f['category']->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->addDays(10),
            'ends_at' => now()->addDays(11),
            'status' => 'draft',
        ];
    }

    private function rawReservation(array $f, array $overrides): array
    {
        return [...[
            'tenant_id' => $f['tenant']->id,
            'reservation_number' => 'RES-06FA-RAW',
            'vehicle_category_id' => $f['category']->id,
            'starts_at' => now()->addDays(20),
            'ends_at' => now()->addDays(21),
            'status' => 'draft',
            'subtotal' => '0.00',
            'options_total' => '0.00',
            'total_amount' => '0.00',
            'deposit_amount' => '0.00',
            'currency' => 'MAD',
            'pricing_snapshot' => '{}',
            'created_by' => $f['owner']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], ...$overrides];
    }

    private function expenseData(array $f): array
    {
        return ['agency_id' => $f['agency']->id, 'category' => 'administration', 'description' => 'Dépense test', 'amount' => '10.00', 'tax_amount' => '0.00', 'currency' => 'MAD', 'expense_date' => today()->toDateString()];
    }

    private function rawExpense(array $f, array $overrides): array
    {
        return [...[
            'tenant_id' => $f['tenant']->id,
            'expense_number' => 'EXP-06FA-RAW',
            'category' => 'administration',
            'description' => 'Insertion directe',
            'amount' => '10.00',
            'tax_amount' => '0.00',
            'currency' => 'MAD',
            'expense_date' => today(),
            'status' => 'draft',
            'created_by' => $f['owner']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], ...$overrides];
    }

    private function paymentData(array $f, RentalContract $contract, string $key): array
    {
        return ['agency_id' => $contract->agency_id, 'rental_contract_id' => $contract->id, 'customer_id' => $contract->customer_id, 'direction' => 'incoming', 'payment_method' => 'cash', 'amount' => '100.00', 'currency' => 'MAD', 'external_reference' => 'REF-06FA', 'idempotency_key' => $key];
    }

    private function vehicleData(Agency $agency, VehicleCategory $category, string $registration): array
    {
        return ['agency_id' => $agency->id, 'vehicle_category_id' => $category->id, 'registration_number' => $registration, 'brand' => 'Dacia', 'model' => 'Logan', 'production_year' => 2025, 'fuel_type' => 'diesel', 'transmission' => 'manual', 'current_mileage' => 1000];
    }

    private function customerData(Agency $agency, string $lastName): array
    {
        return ['agency_id' => $agency->id, 'customer_type' => CustomerType::Individual, 'first_name' => 'Client', 'last_name' => $lastName, 'verification_status' => VerificationStatus::Verified];
    }

    private function driverData(string $suffix): array
    {
        return ['first_name' => 'Conducteur', 'last_name' => $suffix, 'licence_number' => 'LIC-06FA-'.$suffix, 'licence_expires_at' => today()->addYear(), 'verification_status' => VerificationStatus::Verified, 'is_primary' => true];
    }

    private function inTenant(array $f, callable $callback): mixed
    {
        return app(TenantContext::class)->run($f['tenant'], $callback);
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
