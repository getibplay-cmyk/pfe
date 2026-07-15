<?php

namespace Tests\Feature;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\CreateDriver;
use App\Actions\Documents\StorePrivateDocument;
use App\Actions\Finance\AllocatePaymentToInvoice;
use App\Actions\Finance\CloseRentalContract;
use App\Actions\Finance\CreateInvoiceFromReturnedContract;
use App\Actions\Finance\IssueInvoice;
use App\Actions\Finance\PostPayment;
use App\Actions\Finance\RecordDepositReceipt;
use App\Actions\Finance\RecordPayment;
use App\Actions\Finance\RefundDeposit;
use App\Actions\Rentals\AcceptRentalContract;
use App\Actions\Rentals\ActivateRentalContract;
use App\Actions\Rentals\AttachContractVersionDocument;
use App\Actions\Rentals\CompleteDepartureInspection;
use App\Actions\Rentals\CompleteReturnInspection;
use App\Actions\Rentals\CreateRentalContractFromReservation;
use App\Actions\Rentals\MarkContractReady;
use App\Actions\Rentals\MarkRentalReturned;
use App\Actions\Reservations\ConfirmReservation;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Vehicles\CreateVehicle;
use App\Enums\CustomerType;
use App\Enums\DocumentType;
use App\Enums\RentalContractStatus;
use App\Enums\VerificationStatus;
use App\Models\Agency;
use App\Models\Invoice;
use App\Models\PricingRule;
use App\Models\RentalContract;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VehicleCategory;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Lot06EndToEndDemoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('documents.disk'));
    }

    public function test_complete_rental_cycle_is_isolated_audited_and_database_protected(): void
    {
        $this->seed(RolesPermissionsSeeder::class);
        $tenant = Tenant::factory()->create(['name' => 'Atlas Démo Lot 06']);
        $agency = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create());
        $owner = User::factory()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => null,
            'role_id' => Role::where('slug', 'tenant-owner')->value('id'),
        ]);
        $fixture = compact('tenant', 'agency', 'owner');

        $fixture = $this->inTenant($fixture, function () use ($fixture) {
            $category = VehicleCategory::create([
                'code' => 'L06', 'name' => 'Catégorie fictive lot 06', 'is_active' => true,
            ]);
            $vehicle = app(CreateVehicle::class)->handle([
                'agency_id' => $fixture['agency']->id,
                'vehicle_category_id' => $category->id,
                'registration_number' => 'DEMO-L06-'.uniqid(),
                'brand' => 'Dacia', 'model' => 'Logan', 'production_year' => 2025,
                'fuel_type' => 'diesel', 'transmission' => 'manual', 'current_mileage' => 1000,
            ], $fixture['owner']->id);
            $customer = app(CreateCustomer::class)->handle([
                'agency_id' => $fixture['agency']->id,
                'customer_type' => CustomerType::Individual,
                'first_name' => 'Client', 'last_name' => 'Fictif Lot 06',
                'verification_status' => VerificationStatus::Verified,
            ]);
            $driver = app(CreateDriver::class)->handle($customer, [
                'first_name' => 'Conducteur', 'last_name' => 'Fictif Lot 06',
                'licence_number' => 'PERMIS-L06-'.uniqid(),
                'licence_expires_at' => today()->addYears(2),
                'verification_status' => VerificationStatus::Verified,
                'is_primary' => true,
            ]);
            PricingRule::create([
                'agency_id' => null, 'vehicle_category_id' => $category->id,
                'name' => 'Tarif fictif lot 06', 'daily_rate' => '400.00',
                'deposit_amount' => '300.00', 'included_km_per_day' => 200,
                'extra_km_rate' => '2.50', 'late_hour_rate' => '75.00',
                'minimum_days' => 1, 'maximum_days' => 30,
                'valid_from' => today()->subYear(), 'priority' => 0,
                'currency' => 'MAD', 'conditions' => [], 'is_active' => true,
                'created_by' => $fixture['owner']->id,
            ]);
            $startsAt = CarbonImmutable::now()->addDays(2)->startOfHour();
            $reservation = app(CreateReservation::class)->handle([
                'agency_id' => $fixture['agency']->id,
                'customer_id' => $customer->id,
                'driver_id' => $driver->id,
                'vehicle_category_id' => $category->id,
                'vehicle_id' => $vehicle->id,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addDay(),
                'status' => 'draft',
            ], $fixture['owner']->id);
            app(ConfirmReservation::class)->handle($reservation, $fixture['owner']->id);

            return [...$fixture, 'vehicle' => $vehicle, 'customer' => $customer, 'driver' => $driver, 'reservation' => $reservation->refresh()];
        });

        $contract = $this->inTenant($fixture, function () use ($fixture) {
            $contract = app(CreateRentalContractFromReservation::class)->handle($fixture['reservation'], $fixture['owner']->id);
            $pdf = fn (string $name) => UploadedFile::fake()->createWithContent($name, "%PDF-1.4\nDocument strictement fictif\n%%EOF");
            app(StorePrivateDocument::class)->handle($fixture['customer'], ['document_type' => DocumentType::CustomerIdentity, 'title' => 'Identité strictement fictive', 'is_sensitive' => true], $pdf('identite-lot06.pdf'), $fixture['owner']->id);
            app(StorePrivateDocument::class)->handle($fixture['driver'], ['document_type' => DocumentType::DrivingLicence, 'title' => 'Permis strictement fictif', 'is_sensitive' => true], $pdf('permis-lot06.pdf'), $fixture['owner']->id);
            app(AttachContractVersionDocument::class)->handle($contract, $pdf('contrat-lot06.pdf'), $fixture['owner']->id);
            app(MarkContractReady::class)->handle($contract, $fixture['owner']->id);
            app(AcceptRentalContract::class)->handle($contract, [
                'accepted_by_name' => 'Signataire Fictif',
                'acceptance_method' => 'typed_name',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit Lot 06',
            ], $fixture['owner']->id);
            app(CompleteDepartureInspection::class)->handle($contract, [
                'mileage' => 1010, 'fuel_level' => '75.00', 'items' => $this->inspectionItems(),
            ], $fixture['owner']->id);
            app(RecordDepositReceipt::class)->handle($contract, '300.00', 'lot06-deposit-'.$contract->id, $fixture['owner']->id);
            app(ActivateRentalContract::class)->handle($contract, $fixture['owner']->id);
            app(CompleteReturnInspection::class)->handle($contract, [
                'mileage' => 1110, 'fuel_level' => '75.00', 'items' => $this->inspectionItems(),
            ], $fixture['owner']->id);

            return app(MarkRentalReturned::class)->handle($contract, [], $fixture['owner']->id);
        });

        [$invoice, $payment, $closed] = $this->inTenant($fixture, function () use ($fixture, $contract) {
            $invoice = app(CreateInvoiceFromReturnedContract::class)->handle($contract, $fixture['owner']->id);
            $invoice = app(IssueInvoice::class)->handle($invoice, $fixture['owner']->id);
            $payment = app(RecordPayment::class)->handle([
                'agency_id' => $fixture['agency']->id,
                'rental_contract_id' => $contract->id,
                'customer_id' => $fixture['customer']->id,
                'payment_method' => 'cash',
                'amount' => $invoice->total_amount,
                'currency' => 'MAD',
                'idempotency_key' => 'lot06-payment-'.$contract->id,
            ], $fixture['owner']->id);
            app(AllocatePaymentToInvoice::class)->handle($payment, $invoice, $invoice->total_amount);
            $payment = app(PostPayment::class)->handle($payment, $fixture['owner']->id);
            app(RefundDeposit::class)->handle($contract, '300.00', 'lot06-refund-'.$contract->id, $fixture['owner']->id);
            $closed = app(CloseRentalContract::class)->handle($contract, $fixture['owner']->id);

            return [$invoice->refresh(), $payment->refresh(), $closed];
        });

        $this->assertSame(RentalContractStatus::Closed, $closed->status);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('posted', $payment->status);
        $this->assertSame('0.00', $invoice->balance_due);
        $this->assertSame('300.00', $closed->deposit_received);
        $this->assertSame('300.00', $closed->deposit_refunded);
        $this->assertFalse(is_float($invoice->total_amount));
        $this->assertFalse(is_float($closed->amount_paid));
        $this->assertDatabaseHas('vehicle_blocks', [
            'rental_contract_id' => $contract->id, 'block_type' => 'contract', 'status' => 'released',
        ]);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'reservation.confirmed']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'contract.accepted']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'contract.closed']);

        $history = $this->inTenant($fixture, fn () => $closed->statusHistories()->pluck('to_status')->map(fn ($status) => $status->value)->all());
        $this->assertSame(['draft', 'ready', 'accepted', 'active', 'return_pending', 'returned', 'closed'], $history);

        $otherTenant = Tenant::factory()->create();
        $this->assertNull(app(TenantContext::class)->run($otherTenant, fn () => RentalContract::find($closed->id)));
        $this->assertNull(app(TenantContext::class)->run($otherTenant, fn () => Invoice::find($invoice->id)));

        $this->expectException(QueryException::class);
        DB::table('invoice_lines')->where('invoice_id', $invoice->id)->update(['description' => 'Mutation interdite']);
    }

    private function inspectionItems(): array
    {
        return [
            ['item_code' => 'body', 'label' => 'Carrosserie', 'condition' => 'good'],
            ['item_code' => 'interior', 'label' => 'Habitacle', 'condition' => 'good'],
        ];
    }

    private function inTenant(array $fixture, callable $callback): mixed
    {
        return app(TenantContext::class)->run($fixture['tenant'], $callback, $fixture['agency']->id);
    }
}
