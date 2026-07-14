<?php

namespace Tests\Feature;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\CreateDriver;
use App\Actions\Finance\AllocatePaymentToInvoice;
use App\Actions\Finance\CloseRentalContract;
use App\Actions\Finance\CreateInvoiceFromReturnedContract;
use App\Actions\Finance\IssueInvoice;
use App\Actions\Finance\PostPayment;
use App\Actions\Finance\RecordDepositReceipt;
use App\Actions\Finance\RecordPayment;
use App\Actions\Finance\RefundDeposit;
use App\Actions\Finance\RetainDeposit;
use App\Actions\Finance\ReversePayment;
use App\Actions\Finance\VoidInvoice;
use App\Actions\Rentals\CreateRentalContractFromReservation;
use App\Actions\Rentals\GenerateBusinessNumber;
use App\Actions\Reservations\ConfirmReservation;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Vehicles\CreateVehicle;
use App\Enums\CustomerType;
use App\Enums\RentalContractStatus;
use App\Enums\VerificationStatus;
use App\Models\Agency;
use App\Models\ContractCharge;
use App\Models\Invoice;
use App\Models\PricingRule;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VehicleCategory;
use App\Support\Pricing\DecimalMoney;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Lot05FinancePhaseATest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_invoice_requires_returned_contract_and_reviewed_charges(): void
    {
        $f = $this->fixture(false);
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(CreateInvoiceFromReturnedContract::class)->handle($f['contract'], $f['user']->id)), 'status');
        $this->inTenant($f, fn () => $f['contract']->forceFill(['status' => RentalContractStatus::Returned, 'returned_at' => now()])->save());
        $this->inTenant($f, fn () => ContractCharge::create(['rental_contract_id' => $f['contract']->id, 'charge_type' => 'other', 'description' => 'À revoir', 'quantity' => '1.00', 'unit_amount' => '10.00', 'total_amount' => '10.00', 'status' => 'proposed']));
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(CreateInvoiceFromReturnedContract::class)->handle($f['contract']->refresh(), $f['user']->id)), 'charges');
    }

    public function test_invoice_lines_taxes_snapshot_and_hash_are_calculated_without_float(): void
    {
        $f = $this->fixture();
        $this->inTenant($f, fn () => ContractCharge::create(['rental_contract_id' => $f['contract']->id, 'charge_type' => 'cleaning', 'description' => 'Nettoyage validé', 'quantity' => '1.00', 'unit_amount' => '100.00', 'total_amount' => '100.00', 'status' => 'approved', 'approved_by' => $f['user']->id, 'approved_at' => now()]));
        $invoice = $this->inTenant($f, fn () => app(CreateInvoiceFromReturnedContract::class)->handle($f['contract'], $f['user']->id, 'exclusive', '20.0000'));

        $this->assertSame('500.00', $invoice->subtotal);
        $this->assertSame('100.00', $invoice->tax_amount);
        $this->assertSame('600.00', $invoice->total_amount);
        $this->assertCount(2, $invoice->lines);
        $this->assertSame(64, strlen($invoice->content_hash));
        $this->assertSame($f['customer']->displayName(), $invoice->customer_snapshot['name']);
        $this->assertFalse(is_float($invoice->total_amount));
        $this->assertSame('20.00', DecimalMoney::taxForExclusive('100.00', '20.0000'));
        $this->assertSame('20.00', DecimalMoney::taxForInclusive('120.00', '20.0000'));
    }

    public function test_issued_invoice_and_lines_are_immutable_in_postgresql(): void
    {
        $f = $this->fixture();
        $invoice = $this->invoice($f);

        try {
            DB::table('invoices')->where('id', $invoice->id)->update(['tax_rate' => '10.0000']);
            $this->fail('Facture émise modifiée.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
        $this->expectException(QueryException::class);
        DB::table('invoice_lines')->where('invoice_id', $invoice->id)->update(['description' => 'Mutation interdite']);
    }

    public function test_unpaid_issued_invoice_can_be_voided_then_recreated_without_deletion(): void
    {
        $f = $this->fixture();
        $invoice = $this->invoice($f);
        $void = $this->inTenant($f, fn () => app(VoidInvoice::class)->handle($invoice, 'Correction explicite'));
        $replacement = $this->inTenant($f, fn () => app(CreateInvoiceFromReturnedContract::class)->handle($f['contract']->refresh(), $f['user']->id));

        $this->assertSame('void', $void->status);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'void']);
        $this->assertNotSame($invoice->invoice_number, $replacement->invoice_number);
        $this->assertSame($replacement->id, $f['contract']->refresh()->invoice_id);
    }

    public function test_payment_is_idempotent_rejects_card_data_and_prevents_overallocation(): void
    {
        $f = $this->fixture();
        $invoice = $this->invoice($f);
        $data = $this->paymentData($f, '100.00', 'pay-idem');
        $first = $this->inTenant($f, fn () => app(RecordPayment::class)->handle($data, $f['user']->id));
        $again = $this->inTenant($f, fn () => app(RecordPayment::class)->handle($data, $f['user']->id));
        $this->assertSame($first->id, $again->id);
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(RecordPayment::class)->handle([...$data, 'idempotency_key' => 'card-secret', 'card_number' => '4111111111111111'], $f['user']->id)), 'card_number');
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(AllocatePaymentToInvoice::class)->handle($first, $invoice, '101.00')), 'amount');
        $foreignAgency = $this->inTenant($f, fn () => Agency::factory()->create());
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(RecordPayment::class)->handle([...$data, 'agency_id' => $foreignAgency->id, 'idempotency_key' => 'wrong-agency'], $f['user']->id)), 'agency_id');
        $this->assertDatabaseMissing('payments', ['idempotency_key' => 'card-secret']);
    }

    public function test_partial_then_complete_payment_updates_invoice_and_contract(): void
    {
        $f = $this->fixture();
        $invoice = $this->invoice($f);
        $first = $this->pay($f, $invoice, '100.00', 'partial');
        $this->assertSame('partially_paid', $invoice->refresh()->status);
        $this->assertSame('100.00', $invoice->paid_amount);
        $this->assertSame('300.00', $invoice->balance_due);

        $this->pay($f, $invoice, '300.00', 'complete');
        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertSame('0.00', $invoice->balance_due);
        $this->assertSame('400.00', $f['contract']->refresh()->amount_paid);
        $this->assertSame('posted', $first->status);
    }

    public function test_payment_reversal_is_append_only_and_restores_balance(): void
    {
        $f = $this->fixture();
        $invoice = $this->invoice($f);
        $payment = $this->pay($f, $invoice, '400.00', 'to-reverse');
        $reversal = $this->inTenant($f, fn () => app(ReversePayment::class)->handle($payment, 'reverse-payment', 'Erreur de caisse', $f['user']->id));

        $this->assertSame('outgoing', $reversal->direction);
        $this->assertSame('reversed', $payment->refresh()->status);
        $this->assertSame('issued', $invoice->refresh()->status);
        $this->assertSame('400.00', $invoice->balance_due);
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        $this->assertDatabaseHas('payments', ['reversal_of_id' => $payment->id]);
    }

    public function test_deposit_ledger_supports_receipt_retention_refund_and_rejects_excess(): void
    {
        $f = $this->fixture();
        $receipt = $this->inTenant($f, fn () => app(RecordDepositReceipt::class)->handle($f['contract'], '300.00', 'dep-received', $f['user']->id));
        $same = $this->inTenant($f, fn () => app(RecordDepositReceipt::class)->handle($f['contract'], '300.00', 'dep-received', $f['user']->id));
        $this->assertSame($receipt->id, $same->id);
        $this->inTenant($f, fn () => app(RetainDeposit::class)->handle($f['contract'], '50.00', 'dep-retained', 'Nettoyage décidé humainement', $f['user']->id));
        $this->inTenant($f, fn () => app(RefundDeposit::class)->handle($f['contract'], '250.00', 'dep-refunded', $f['user']->id));
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(RefundDeposit::class)->handle($f['contract'], '1.00', 'dep-excess', $f['user']->id)), 'amount');
        $contract = $f['contract']->refresh();
        $this->assertSame('300.00', $contract->deposit_received);
        $this->assertSame('50.00', $contract->deposit_retained);
        $this->assertSame('250.00', $contract->deposit_refunded);
    }

    public function test_deposit_rows_are_immutable_in_postgresql(): void
    {
        $f = $this->fixture();
        $entry = $this->inTenant($f, fn () => app(RecordDepositReceipt::class)->handle($f['contract'], '100.00', 'immutable-deposit', $f['user']->id));
        $this->expectException(QueryException::class);
        DB::table('deposit_transactions')->where('id', $entry->id)->update(['amount' => '99.00']);
    }

    public function test_closure_rejects_balance_or_unsettled_deposit_then_succeeds_with_history(): void
    {
        $f = $this->fixture();
        $invoice = $this->invoice($f);
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(CloseRentalContract::class)->handle($f['contract'], $f['user']->id)), 'invoice');
        $this->pay($f, $invoice, '400.00', 'closure-payment');
        $this->inTenant($f, fn () => app(RecordDepositReceipt::class)->handle($f['contract'], '300.00', 'closure-deposit', $f['user']->id));
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(CloseRentalContract::class)->handle($f['contract'], $f['user']->id)), 'deposit');
        $this->inTenant($f, fn () => app(RefundDeposit::class)->handle($f['contract'], '300.00', 'closure-refund', $f['user']->id));
        $closed = $this->inTenant($f, fn () => app(CloseRentalContract::class)->handle($f['contract'], $f['user']->id));

        $this->assertSame(RentalContractStatus::Closed, $closed->status);
        $this->assertNotNull($closed->financially_settled_at);
        $this->assertDatabaseHas('contract_status_histories', ['rental_contract_id' => $closed->id, 'from_status' => 'returned', 'to_status' => 'closed']);
    }

    public function test_database_rejects_direct_unsettled_closure_and_suite_is_tenant_scoped_postgresql(): void
    {
        $f = $this->fixture();
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('rentfleet_test', DB::connection()->getDatabaseName());
        $other = $this->fixture();
        $invoice = $this->inTenant($f, fn () => app(CreateInvoiceFromReturnedContract::class)->handle($f['contract'], $f['user']->id));
        $this->assertNull($this->inTenant($other, fn () => Invoice::find($invoice->id)));
        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{6}$/', $invoice->invoice_number);
        [$numberA, $numberB] = $this->inTenant($f, fn () => [app(GenerateBusinessNumber::class)->handle('payment'), app(GenerateBusinessNumber::class)->handle('payment')]);
        $this->assertNotSame($numberA, $numberB);
        $this->assertMatchesRegularExpression('/^PAY-\d{4}-\d{6}$/', $numberA);

        $this->expectException(QueryException::class);
        DB::table('rental_contracts')->where('id', $f['contract']->id)->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $f['user']->id, 'financially_settled_at' => now()]);
    }

    private function fixture(bool $returned = true): array
    {
        $tenant = Tenant::factory()->create();
        $agency = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create());
        $role = Role::where('slug', 'tenant-owner')->firstOrFail();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => null, 'role_id' => $role->id]);
        $f = compact('tenant', 'agency', 'user');

        return $this->inTenant($f, function () use ($f, $returned) {
            $category = VehicleCategory::create(['code' => 'F-'.uniqid(), 'name' => 'Finance', 'is_active' => true]);
            $vehicle = app(CreateVehicle::class)->handle(['agency_id' => $f['agency']->id, 'vehicle_category_id' => $category->id, 'registration_number' => 'RF-'.uniqid(), 'brand' => 'Dacia', 'model' => 'Logan', 'production_year' => 2025, 'fuel_type' => 'diesel', 'transmission' => 'manual', 'current_mileage' => 1000], $f['user']->id);
            $customer = app(CreateCustomer::class)->handle(['agency_id' => $f['agency']->id, 'customer_type' => CustomerType::Individual, 'first_name' => 'Client', 'last_name' => 'Finance', 'verification_status' => VerificationStatus::Verified]);
            $driver = app(CreateDriver::class)->handle($customer, ['first_name' => 'Conducteur', 'last_name' => 'Finance', 'licence_number' => 'PERMIS-'.uniqid(), 'licence_expires_at' => today()->addYear(), 'verification_status' => VerificationStatus::Verified, 'is_primary' => true]);
            PricingRule::create(['agency_id' => null, 'vehicle_category_id' => $category->id, 'name' => 'Tarif finance', 'daily_rate' => '400.00', 'deposit_amount' => '300.00', 'included_km_per_day' => 200, 'extra_km_rate' => '2.50', 'late_hour_rate' => '75.00', 'minimum_days' => 1, 'maximum_days' => 30, 'valid_from' => today()->subYear(), 'priority' => 0, 'currency' => 'MAD', 'conditions' => [], 'is_active' => true, 'created_by' => $f['user']->id]);
            $start = CarbonImmutable::now()->addDays(3)->startOfHour();
            $reservation = app(CreateReservation::class)->handle(['agency_id' => $f['agency']->id, 'customer_id' => $customer->id, 'driver_id' => $driver->id, 'vehicle_category_id' => $category->id, 'vehicle_id' => $vehicle->id, 'starts_at' => $start, 'ends_at' => $start->addDay(), 'status' => 'draft'], $f['user']->id);
            app(ConfirmReservation::class)->handle($reservation, $f['user']->id);
            $contract = app(CreateRentalContractFromReservation::class)->handle($reservation->refresh(), $f['user']->id);
            if ($returned) {
                $contract->forceFill(['status' => RentalContractStatus::Returned, 'returned_at' => now(), 'actual_return_at' => now()])->save();
            }

            return [...$f, 'vehicle' => $vehicle, 'customer' => $customer, 'driver' => $driver, 'contract' => $contract->refresh()];
        });
    }

    private function invoice(array $f): Invoice
    {
        return $this->inTenant($f, function () use ($f) {
            $invoice = app(CreateInvoiceFromReturnedContract::class)->handle($f['contract'], $f['user']->id);

            return app(IssueInvoice::class)->handle($invoice, $f['user']->id);
        });
    }

    private function paymentData(array $f, string $amount, string $key): array
    {
        return ['agency_id' => $f['agency']->id, 'rental_contract_id' => $f['contract']->id, 'customer_id' => $f['customer']->id, 'payment_method' => 'cash', 'amount' => $amount, 'currency' => 'MAD', 'idempotency_key' => $key];
    }

    private function pay(array $f, Invoice $invoice, string $amount, string $key)
    {
        return $this->inTenant($f, function () use ($f, $invoice, $amount, $key) {
            $payment = app(RecordPayment::class)->handle($this->paymentData($f, $amount, $key), $f['user']->id);
            app(AllocatePaymentToInvoice::class)->handle($payment, $invoice->refresh(), $amount);

            return app(PostPayment::class)->handle($payment, $f['user']->id);
        });
    }

    private function inTenant(array $fixture, callable $callback): mixed
    {
        return app(TenantContext::class)->run($fixture['tenant'], $callback, $fixture['agency']->id);
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
}
