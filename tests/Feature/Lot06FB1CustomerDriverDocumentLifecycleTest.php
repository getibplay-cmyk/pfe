<?php

namespace Tests\Feature;

use App\Actions\Customers\ArchiveCustomer;
use App\Actions\Customers\ArchiveDriver;
use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\CreateDriver;
use App\Actions\Customers\RestoreCustomer;
use App\Actions\Customers\RestoreDriver;
use App\Actions\Customers\VerifyCustomer;
use App\Actions\Customers\VerifyDriver;
use App\Actions\Documents\ArchiveDocument;
use App\Actions\Documents\StorePrivateDocument;
use App\Actions\Pricing\CreatePricingRule;
use App\Actions\Rentals\AttachContractVersionDocument;
use App\Actions\Rentals\CreateRentalContractFromReservation;
use App\Actions\Reservations\ConfirmReservation;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Vehicles\CreateVehicle;
use App\Enums\CustomerType;
use App\Enums\DocumentType;
use App\Enums\VerificationStatus;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Driver;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VehicleCategory;
use App\Support\SensitiveData\IdentityProtector;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Lot06FB1CustomerDriverDocumentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
        Storage::fake(config('documents.disk'));
    }

    public function test_customer_creation_forces_pending_rejects_forged_status_and_requires_agency(): void
    {
        $f = $this->fixture();
        $data = $this->customerData($f['agency']);

        $this->actingAs($f['owner'])->post(route('customers.store'), [...$data, 'verification_status' => 'verified'])
            ->assertSessionHasErrors('verification_status');
        $this->actingAs($f['owner'])->post(route('customers.store'), array_diff_key($data, ['agency_id' => true]))
            ->assertSessionHasErrors('agency_id');

        $this->actingAs($f['owner'])->post(route('customers.store'), $data)->assertRedirect();
        $customer = $this->inTenant($f, fn () => Customer::latest('id')->firstOrFail());
        $this->assertSame(VerificationStatus::Pending, $customer->verification_status);
        $this->assertSame($f['agency']->id, $customer->agency_id);

        $direct = $this->inTenant($f, fn () => app(CreateCustomer::class)->handle([...$data, 'verification_status' => 'verified']));
        $this->assertSame(VerificationStatus::Pending, $direct->verification_status);
    }

    public function test_customer_update_rejects_status_and_incompatible_agency(): void
    {
        $f = $this->fixture();
        $otherAgency = $this->inTenant($f, fn () => Agency::factory()->create());
        $customer = $this->customer($f);
        $category = $this->inTenant($f, fn () => VehicleCategory::create(['code' => 'B1-'.uniqid(), 'name' => 'Catégorie B1', 'is_active' => true]));
        $reservation = $this->inTenant($f, fn () => app(CreateReservation::class)->handle([
            'agency_id' => $f['agency']->id,
            'customer_id' => $customer->id,
            'vehicle_category_id' => $category->id,
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(4),
            'status' => 'draft',
        ], $f['owner']->id));

        $update = [...$this->customerData($otherAgency), 'first_name' => 'Client modifié'];
        $this->actingAs($f['owner'])->put(route('customers.update', $customer), [...$update, 'verification_status' => 'verified'])
            ->assertSessionHasErrors('verification_status');
        $this->actingAs($f['owner'])->put(route('customers.update', $customer), $update)
            ->assertSessionHasErrors('agency_id');

        $this->assertSame($f['agency']->id, $this->inTenant($f, fn () => $customer->refresh()->agency_id));
        $this->assertSame($f['agency']->id, $reservation->agency_id);
    }

    public function test_customer_verification_requires_an_integral_private_identity_document(): void
    {
        $f = $this->fixture();
        $customer = $this->customer($f);

        $this->actingAs($f['owner'])->post(route('customers.verify', $customer))->assertSessionHasErrors('verification');
        $document = $this->document($f, $customer, DocumentType::CustomerIdentity, 'identite-b1.pdf');
        $version = $this->inTenant($f, fn () => $document->currentVersion()->firstOrFail());
        $this->inTenant($f, fn () => $version->forceFill(['sha256' => str_repeat('0', 64)])->save());
        $this->actingAs($f['owner'])->post(route('customers.verify', $customer))->assertSessionHasErrors('verification');

        $contents = Storage::disk(config('documents.disk'))->get($version->stored_path);
        $this->inTenant($f, fn () => $version->forceFill(['sha256' => hash('sha256', $contents)])->save());
        $this->actingAs($f['owner'])->post(route('customers.verify', $customer))->assertRedirect();

        $this->assertSame(VerificationStatus::Verified, $this->inTenant($f, fn () => $customer->refresh()->verification_status));
        $audit = DB::table('audit_logs')->where('action', 'customer.verification.verified')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('identity_number', json_encode($audit));
    }

    public function test_driver_creation_update_reencrypts_licence_and_refuses_cross_agency_writes(): void
    {
        $f = $this->fixture();
        $customer = $this->customer($f);
        $create = $this->driverData('LICENCE-B1-ORIGINALE');

        $this->actingAs($f['owner'])->post(route('customers.drivers.store', $customer), [...$create, 'verification_status' => 'verified'])
            ->assertSessionHasErrors('verification_status');
        $this->actingAs($f['owner'])->post(route('customers.drivers.store', $customer), $create)->assertRedirect();
        $driver = $this->inTenant($f, fn () => Driver::latest('id')->firstOrFail());
        $this->assertSame(VerificationStatus::Pending, $driver->verification_status);

        $raw = DB::table('drivers')->find($driver->id);
        $this->assertStringNotContainsString('LICENCE-B1-ORIGINALE', $raw->licence_number_encrypted);
        $update = [...$create, 'licence_number' => 'LICENCE-B1-NOUVELLE', 'first_name' => 'Nouveau'];
        $this->actingAs($f['owner'])->put(route('drivers.update', $driver), [...$update, 'verification_status' => 'verified'])
            ->assertSessionHasErrors('verification_status');
        $this->actingAs($f['owner'])->put(route('drivers.update', $driver), $update)->assertRedirect();

        $driver = $this->inTenant($f, fn () => $driver->refresh());
        $this->assertSame('LICENCEB1NOUVELLE', app(IdentityProtector::class)->reveal($driver->licence_number_encrypted));
        $this->assertStringNotContainsString('LICENCE-B1-NOUVELLE', DB::table('audit_logs')->get()->toJson());
        $this->actingAs($f['owner'])->get(route('drivers.show', $driver))->assertOk()->assertDontSee('LICENCEB1NOUVELLE');

        $otherAgency = $this->inTenant($f, fn () => Agency::factory()->create());
        $foreignCustomer = $this->inTenant($f, fn () => app(CreateCustomer::class)->handle($this->customerData($otherAgency)));
        $foreignDriver = $this->inTenant($f, fn () => app(CreateDriver::class)->handle($foreignCustomer, $create));
        $manager = User::factory()->create(['tenant_id' => $f['tenant']->id, 'agency_id' => $f['agency']->id, 'role_id' => Role::where('slug', 'agency-manager')->value('id')]);
        $this->actingAs($manager)->put(route('drivers.update', $foreignDriver), $update)->assertForbidden();
    }

    public function test_driver_verification_requires_private_valid_non_expired_licence(): void
    {
        $f = $this->fixture();
        $customer = $this->customer($f);
        $driver = $this->driver($f, $customer);

        $this->actingAs($f['owner'])->post(route('drivers.verify', $driver))->assertSessionHasErrors('verification');
        $this->document($f, $driver, DocumentType::DrivingLicence, 'permis-b1.pdf');
        $this->inTenant($f, fn () => $driver->forceFill(['licence_expires_at' => today()->subDay()])->save());
        $this->actingAs($f['owner'])->post(route('drivers.verify', $driver))->assertSessionHasErrors('licence_expires_at');

        $this->inTenant($f, fn () => $driver->forceFill(['licence_expires_at' => today()->addYear()])->save());
        $this->actingAs($f['owner'])->post(route('drivers.verify', $driver))->assertRedirect();
        $this->assertSame(VerificationStatus::Verified, $this->inTenant($f, fn () => $driver->refresh()->verification_status));
    }

    public function test_primary_driver_is_transactional_and_protected_by_postgresql(): void
    {
        $f = $this->fixture();
        $customer = $this->customer($f);
        $first = $this->driver($f, $customer, 'PREMIER', true);
        $second = $this->driver($f, $customer, 'SECOND', true);

        $this->assertFalse($this->inTenant($f, fn () => $first->refresh()->is_primary));
        $this->assertTrue($this->inTenant($f, fn () => $second->refresh()->is_primary));
        $this->assertSame(1, DB::table('drivers')->where('customer_id', $customer->id)->whereNull('deleted_at')->where('is_primary', true)->count());

        try {
            DB::transaction(fn () => DB::table('drivers')->where('id', $first->id)->update(['is_primary' => true]));
            $this->fail('La contrainte partielle PostgreSQL devait refuser un second conducteur principal.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->getCode());
        }

        $index = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE indexname = 'drivers_one_active_primary_per_customer_idx'");
        $this->assertStringContainsString('WHERE ((is_primary = true) AND (deleted_at IS NULL))', $index->indexdef);
    }

    public function test_driver_and_customer_archives_refuse_active_cycles_but_preserve_history(): void
    {
        $f = $this->fixture();
        [$customer, $driver] = $this->verifiedParties($f);
        $business = $this->business($f);
        $reservation = $this->reservation($f, $business, $customer, $driver);

        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(ArchiveDriver::class)->handle($driver)), 'driver');
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(ArchiveCustomer::class)->handle($customer)), 'customer');

        $this->inTenant($f, fn () => $reservation->forceFill(['status' => 'expired'])->save());
        $this->inTenant($f, fn () => app(ArchiveDriver::class)->handle($driver));
        $this->assertSoftDeleted('drivers', ['id' => $driver->id]);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'driver_id' => $driver->id, 'status' => 'expired']);
        $this->inTenant($f, fn () => app(RestoreDriver::class)->handle(Driver::withTrashed()->findOrFail($driver->id)));

        $this->inTenant($f, fn () => app(ArchiveCustomer::class)->handle($customer));
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'customer_id' => $customer->id]);
        $this->inTenant($f, fn () => app(RestoreCustomer::class)->handle(Customer::withTrashed()->findOrFail($customer->id)));
        $this->assertNull($this->inTenant($f, fn () => $customer->refresh()->deleted_at));
    }

    public function test_document_archive_protects_required_and_contractual_files_and_preserves_storage(): void
    {
        $f = $this->fixture();
        [$customer, $driver] = $this->verifiedParties($f);
        $identity = $this->inTenant($f, fn () => $customer->documents()->where('document_type', DocumentType::CustomerIdentity->value)->firstOrFail());
        $licence = $this->inTenant($f, fn () => $driver->documents()->where('document_type', DocumentType::DrivingLicence->value)->firstOrFail());

        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(ArchiveDocument::class)->handle($identity)), 'document');
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(ArchiveDocument::class)->handle($licence)), 'document');

        $older = $this->document($f, $customer, DocumentType::CustomerIdentity, 'ancienne-identite.pdf');
        $path = $this->inTenant($f, fn () => $older->currentVersion()->value('stored_path'));
        $this->inTenant($f, fn () => app(ArchiveDocument::class)->handle($identity));
        $this->assertSoftDeleted('documents', ['id' => $identity->id]);
        Storage::disk(config('documents.disk'))->assertExists($this->inTenant($f, fn () => $identity->currentVersion()->value('stored_path')));
        $this->actingAs($f['owner'])->get(route('documents.show', $identity->id))->assertNotFound();
        Storage::disk(config('documents.disk'))->assertExists($path);

        $business = $this->business($f);
        $reservation = $this->reservation($f, $business, $customer, $driver);
        $this->inTenant($f, fn () => app(ConfirmReservation::class)->handle($reservation, $f['owner']->id));
        $contract = $this->inTenant($f, fn () => app(CreateRentalContractFromReservation::class)->handle($reservation, $f['owner']->id));
        $this->inTenant($f, fn () => app(AttachContractVersionDocument::class)->handle($contract, $this->pdf('contrat-b1.pdf'), $f['owner']->id));
        $contractDocument = $this->inTenant($f, fn () => $contract->currentVersion()->firstOrFail()->document()->firstOrFail());
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(ArchiveDocument::class)->handle($contractDocument)), 'document');
    }

    public function test_full_licence_revelation_is_permission_protected_and_audited(): void
    {
        $f = $this->fixture();
        $customer = $this->customer($f);
        $driver = $this->driver($f, $customer, 'LICENCE-REVEAL-B1');

        $this->actingAs($f['owner'])->get(route('drivers.licence', $driver))
            ->assertOk()->assertSee('LICENCEREVEALB1')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('audit_logs', ['action' => 'driver.licence.viewed', 'auditable_id' => $driver->id]);
        $this->assertStringNotContainsString('LICENCEREVEALB1', DB::table('audit_logs')->get()->toJson());

        $viewer = User::factory()->create(['tenant_id' => $f['tenant']->id, 'agency_id' => $f['agency']->id, 'role_id' => Role::where('slug', 'viewer-auditor')->value('id')]);
        $this->actingAs($viewer)->get(route('drivers.licence', $driver))->assertForbidden();
    }

    public function test_web_customer_driver_document_to_confirmed_reservation_journey(): void
    {
        $f = $this->fixture();
        $business = $this->business($f);
        $this->actingAs($f['owner']);

        $this->post(route('customers.store'), $this->customerData($f['agency']))->assertRedirect();
        $customer = $this->inTenant($f, fn () => Customer::latest('id')->firstOrFail());
        $this->post(route('customers.documents.store', $customer), $this->documentPayload(DocumentType::CustomerIdentity, 'identite-web.pdf'))->assertRedirect();
        $this->post(route('customers.verify', $customer))->assertRedirect();

        $this->post(route('customers.drivers.store', $customer), $this->driverData('LICENCE-WEB-B1'))->assertRedirect();
        $driver = $this->inTenant($f, fn () => Driver::latest('id')->firstOrFail());
        $this->post(route('drivers.documents.store', $driver), $this->documentPayload(DocumentType::DrivingLicence, 'permis-web.pdf'))->assertRedirect();
        $this->post(route('drivers.verify', $driver))->assertRedirect();

        $starts = now()->addDays(10)->startOfHour();
        $response = $this->post(route('reservations.store'), [
            'agency_id' => $f['agency']->id,
            'customer_id' => $customer->id,
            'driver_id' => $driver->id,
            'vehicle_category_id' => $business['category']->id,
            'vehicle_id' => $business['vehicle']->id,
            'starts_at' => $starts->format('Y-m-d\TH:i'),
            'ends_at' => $starts->addDays(2)->format('Y-m-d\TH:i'),
            'status' => 'draft',
        ])->assertRedirect();
        $reservation = $this->inTenant($f, fn () => Reservation::latest('id')->firstOrFail());
        $response->assertRedirect(route('reservations.show', $reservation));
        $this->post(route('reservations.confirm', $reservation))->assertRedirect();
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'confirmed']);
        $this->assertDatabaseHas('vehicle_blocks', ['reservation_id' => $reservation->id, 'status' => 'active']);
    }

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create();
        $agency = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create());
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => null, 'role_id' => Role::where('slug', 'tenant-owner')->value('id')]);

        return compact('tenant', 'agency', 'owner');
    }

    private function customer(array $f): Customer
    {
        return $this->inTenant($f, fn () => app(CreateCustomer::class)->handle($this->customerData($f['agency'])));
    }

    private function driver(array $f, Customer $customer, string $licence = 'LICENCE-B1', bool $primary = false): Driver
    {
        return $this->inTenant($f, fn () => app(CreateDriver::class)->handle($customer, [...$this->driverData($licence), 'is_primary' => $primary]));
    }

    private function verifiedParties(array $f): array
    {
        $customer = $this->customer($f);
        $this->document($f, $customer, DocumentType::CustomerIdentity, 'identite-verifiee.pdf');
        $this->inTenant($f, fn () => app(VerifyCustomer::class)->handle($customer));
        $driver = $this->driver($f, $customer, 'LICENCE-VERIFIEE', true);
        $this->document($f, $driver, DocumentType::DrivingLicence, 'permis-verifie.pdf');
        $this->inTenant($f, fn () => app(VerifyDriver::class)->handle($driver));

        return $this->inTenant($f, fn () => [$customer->refresh(), $driver->refresh()]);
    }

    private function document(array $f, Customer|Driver $owner, DocumentType $type, string $name): Document
    {
        return $this->inTenant($f, fn () => app(StorePrivateDocument::class)->handle($owner, [
            'document_type' => $type,
            'title' => $type->value,
            'is_sensitive' => true,
        ], $this->pdf($name), $f['owner']->id));
    }

    private function business(array $f): array
    {
        return $this->inTenant($f, function () use ($f): array {
            $category = VehicleCategory::create(['code' => 'B1-'.uniqid(), 'name' => 'Compact B1', 'is_active' => true]);
            $vehicle = app(CreateVehicle::class)->handle([
                'agency_id' => $f['agency']->id, 'vehicle_category_id' => $category->id, 'registration_number' => 'B1-'.uniqid(),
                'brand' => 'Dacia', 'model' => 'Sandero', 'production_year' => 2025, 'fuel_type' => 'diesel', 'transmission' => 'manual', 'current_mileage' => 1000,
            ], $f['owner']->id);
            $pricing = app(CreatePricingRule::class)->handle([
                'agency_id' => $f['agency']->id, 'vehicle_category_id' => $category->id, 'name' => 'Tarif B1', 'daily_rate' => '400.00',
                'deposit_amount' => '500.00', 'included_km_per_day' => 200, 'extra_km_rate' => '2.00', 'late_hour_rate' => '50.00',
                'minimum_days' => 1, 'maximum_days' => 30, 'valid_from' => today()->subYear(), 'priority' => 1, 'currency' => 'MAD', 'conditions' => [], 'is_active' => true,
            ], $f['owner']->id);

            return compact('category', 'vehicle', 'pricing');
        });
    }

    private function reservation(array $f, array $business, Customer $customer, Driver $driver): Reservation
    {
        return $this->inTenant($f, fn () => app(CreateReservation::class)->handle([
            'agency_id' => $f['agency']->id, 'customer_id' => $customer->id, 'driver_id' => $driver->id,
            'vehicle_category_id' => $business['category']->id, 'vehicle_id' => $business['vehicle']->id,
            'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(7), 'status' => 'draft',
        ], $f['owner']->id));
    }

    private function customerData(Agency $agency): array
    {
        return ['agency_id' => $agency->id, 'customer_type' => CustomerType::Individual->value, 'first_name' => 'Client', 'last_name' => 'Lot B1', 'email' => 'client-'.uniqid().'@example.test'];
    }

    private function driverData(string $licence): array
    {
        return ['first_name' => 'Conducteur', 'last_name' => 'Lot B1', 'licence_number' => $licence, 'licence_category' => 'B', 'licence_issued_at' => today()->subYear()->toDateString(), 'licence_expires_at' => today()->addYear()->toDateString(), 'is_primary' => false];
    }

    private function documentPayload(DocumentType $type, string $name): array
    {
        return ['document_type' => $type->value, 'title' => $type->value, 'is_sensitive' => '1', 'file' => $this->pdf($name)];
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\nLot 06F-B1\n%%EOF");
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
}
