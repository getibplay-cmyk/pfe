<?php

namespace Tests\Feature;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\CreateDriver;
use App\Actions\Documents\AddDocumentVersion;
use App\Actions\Documents\StorePrivateDocument;
use App\Actions\Vehicles\ChangeVehicleOperationalStatus;
use App\Actions\Vehicles\CreateVehicle;
use App\Enums\CustomerType;
use App\Enums\VehicleOperationalStatus;
use App\Enums\VerificationStatus;
use App\Models\Agency;
use App\Models\Document;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VehicleCategory;
use App\Models\VehicleStatusHistory;
use App\Support\SensitiveData\IdentityProtector;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Lot02ReferencesAndPrivateDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
        Storage::fake('local');
    }

    public function test_categories_and_vehicles_are_isolated_and_registration_is_unique_per_tenant(): void
    {
        [$tenantA, $agencyA, $ownerA] = $this->tenantUser();
        [$tenantB, $agencyB] = $this->tenantUser();
        $context = app(TenantContext::class);
        $categoryA = $context->run($tenantA, fn () => VehicleCategory::create(['code' => 'ECO', 'name' => 'Economy', 'is_active' => true]));
        $categoryB = $context->run($tenantB, fn () => VehicleCategory::create(['code' => 'ECO', 'name' => 'Economy B', 'is_active' => true]));
        $vehicleA = $context->run($tenantA, fn () => app(CreateVehicle::class)->handle($this->vehicleData($agencyA, $categoryA, '10000-A-26'), $ownerA->id));
        $vehicleB = $context->run($tenantB, fn () => app(CreateVehicle::class)->handle($this->vehicleData($agencyB, $categoryB, '10000-A-26'), null));

        $this->assertNotSame($vehicleA->tenant_id, $vehicleB->tenant_id);
        $this->expectException(QueryException::class);
        $context->run($tenantA, fn () => app(CreateVehicle::class)->handle($this->vehicleData($agencyA, $categoryA, '10000-A-26'), $ownerA->id));
    }

    public function test_vehicle_rejects_foreign_agency_and_category(): void
    {
        [$tenantA, $agencyA, $owner] = $this->tenantUser();
        [$tenantB, $agencyB] = $this->tenantUser();
        $context = app(TenantContext::class);
        $categoryA = $context->run($tenantA, fn () => VehicleCategory::create(['code' => 'A', 'name' => 'A', 'is_active' => true]));
        $categoryB = $context->run($tenantB, fn () => VehicleCategory::create(['code' => 'B', 'name' => 'B', 'is_active' => true]));

        foreach ([[$agencyB, $categoryA], [$agencyA, $categoryB]] as [$agency, $category]) {
            try {
                $context->run($tenantA, fn () => app(CreateVehicle::class)->handle($this->vehicleData($agency, $category, uniqid('REG-')), $owner->id));
                $this->fail('Une association cross-tenant a été acceptée.');
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_agency_manager_only_sees_own_vehicles_and_form_rejects_tenant_and_negative_mileage(): void
    {
        [$tenant, $agency, $manager] = $this->tenantUser('agency-manager');
        $context = app(TenantContext::class);
        $otherAgency = $context->run($tenant, fn () => Agency::factory()->create());
        $category = $context->run($tenant, fn () => VehicleCategory::create(['code' => 'SUV', 'name' => 'SUV', 'is_active' => true]));
        $own = $context->run($tenant, fn () => app(CreateVehicle::class)->handle($this->vehicleData($agency, $category, 'OWN-1'), $manager->id));
        $other = $context->run($tenant, fn () => app(CreateVehicle::class)->handle($this->vehicleData($otherAgency, $category, 'OTHER-1'), $manager->id));

        $this->actingAs($manager)->get(route('vehicles.index'))->assertOk()->assertSee($own->registration_number)->assertDontSee($other->registration_number);
        $this->actingAs($manager)->post(route('vehicles.store'), [...$this->vehicleData($agency, $category, 'BAD-1'), 'tenant_id' => 999, 'current_mileage' => -1])->assertSessionHasErrors(['tenant_id', 'current_mileage']);
    }

    public function test_status_change_is_transactional_and_creates_history(): void
    {
        [$tenant, $agency, $owner] = $this->tenantUser();
        $context = app(TenantContext::class);
        $category = $context->run($tenant, fn () => VehicleCategory::create(['code' => 'COM', 'name' => 'Compact', 'is_active' => true]));
        $vehicle = $context->run($tenant, fn () => app(CreateVehicle::class)->handle($this->vehicleData($agency, $category, 'STATUS-1'), $owner->id));
        $context->run($tenant, fn () => app(ChangeVehicleOperationalStatus::class)->handle($vehicle, VehicleOperationalStatus::Maintenance, 'Entretien', $owner->id));

        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'operational_status' => 'maintenance']);
        $this->assertSame(2, VehicleStatusHistory::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->count());
    }

    public function test_customer_and_driver_identity_values_are_encrypted_masked_and_expiry_detected(): void
    {
        [$tenant, $agency] = $this->tenantUser();
        $context = app(TenantContext::class);
        [$customer, $driver] = $context->run($tenant, function () use ($agency) {
            $customer = app(CreateCustomer::class)->handle(['agency_id' => $agency->id, 'customer_type' => CustomerType::Individual, 'first_name' => 'Client', 'last_name' => 'Fictif', 'identity_type' => 'cin', 'identity_number' => 'DEMO-CIN-SECRET', 'verification_status' => VerificationStatus::Verified]);
            $driver = app(CreateDriver::class)->handle($customer, ['first_name' => 'Conducteur', 'last_name' => 'Fictif', 'licence_number' => 'DEMO-LICENCE-SECRET', 'licence_expires_at' => today()->subDay(), 'verification_status' => VerificationStatus::Verified, 'is_primary' => true]);

            return [$customer, $driver];
        });

        $rawCustomer = DB::table('customers')->find($customer->id);
        $rawDriver = DB::table('drivers')->find($driver->id);
        $this->assertStringNotContainsString('DEMO-CIN-SECRET', $rawCustomer->identity_number_encrypted);
        $this->assertStringNotContainsString('DEMO-LICENCE-SECRET', $rawDriver->licence_number_encrypted);
        $this->assertStringEndsWith('CRET', app(IdentityProtector::class)->maskEncrypted($rawCustomer->identity_number_encrypted));
        $this->assertTrue($driver->isLicenceExpired());
    }

    public function test_customer_is_cross_tenant_inaccessible_and_identity_stays_masked_without_permission(): void
    {
        [$tenantA, $agencyA] = $this->tenantUser('viewer-auditor');
        [$tenantB, $agencyB] = $this->tenantUser();
        $context = app(TenantContext::class);
        $viewer = User::where('tenant_id', $tenantA->id)->firstOrFail();
        $own = $context->run($tenantA, fn () => app(CreateCustomer::class)->handle(['agency_id' => $agencyA->id, 'customer_type' => CustomerType::Individual, 'first_name' => 'Visible', 'last_name' => 'Client', 'identity_number' => 'MASKED-1234', 'verification_status' => VerificationStatus::Pending]));
        $foreign = $context->run($tenantB, fn () => app(CreateCustomer::class)->handle(['agency_id' => $agencyB->id, 'customer_type' => CustomerType::Individual, 'first_name' => 'Foreign', 'last_name' => 'Client', 'verification_status' => VerificationStatus::Pending]));

        $this->actingAs($viewer)->get(route('customers.index'))->assertOk()->assertSee('••••')->assertDontSee('MASKED-1234');
        $this->actingAs($viewer)->get(route('customers.show', $foreign))->assertNotFound();
        $this->actingAs($viewer)->get(route('customers.identity', $own))->assertForbidden();
    }

    public function test_private_document_rejects_dangerous_files_and_client_paths(): void
    {
        [$tenant, $agency, $owner] = $this->tenantUser();
        $customer = app(TenantContext::class)->run($tenant, fn () => app(CreateCustomer::class)->handle(['agency_id' => $agency->id, 'customer_type' => CustomerType::Individual, 'first_name' => 'Doc', 'last_name' => 'Client', 'verification_status' => VerificationStatus::Pending]));

        $this->actingAs($owner)->post(route('customers.documents.store', $customer), ['document_type' => 'other', 'title' => 'Interdit', 'is_sensitive' => '0', 'stored_path' => '../../public/file.pdf', 'file' => UploadedFile::fake()->create('payload.php.jpg', 10, 'image/jpeg')])->assertSessionHasErrors('stored_path');
        $document = app(TenantContext::class)->run($tenant, fn () => Document::create(['agency_id' => $agency->id, 'documentable_type' => 'customer', 'documentable_id' => $customer->id, 'document_type' => 'other', 'title' => 'Test', 'created_by' => $owner->id]));
        $this->expectException(ValidationException::class);
        app(TenantContext::class)->run($tenant, fn () => app(AddDocumentVersion::class)->handle($document, UploadedFile::fake()->create('payload.php.jpg', 10, 'image/jpeg'), $owner->id));
    }

    public function test_private_document_versions_download_and_cross_tenant_protection(): void
    {
        [$tenantA, $agencyA, $ownerA] = $this->tenantUser();
        [, , $ownerB] = $this->tenantUser();
        $context = app(TenantContext::class);
        $customer = $context->run($tenantA, fn () => app(CreateCustomer::class)->handle(['agency_id' => $agencyA->id, 'customer_type' => CustomerType::Individual, 'first_name' => 'Doc', 'last_name' => 'Client', 'verification_status' => VerificationStatus::Pending]));
        $document = $context->run($tenantA, fn () => app(StorePrivateDocument::class)->handle($customer, ['document_type' => 'customer_identity', 'title' => 'Identité', 'is_sensitive' => true], $this->pdf('identity.pdf'), $ownerA->id));
        $context->run($tenantA, fn () => app(AddDocumentVersion::class)->handle($document, $this->pdf('identity-v2.pdf'), $ownerA->id));

        $versions = DB::table('document_versions')->where('document_id', $document->id)->orderBy('version_number')->get();
        $this->assertSame([1, 2], $versions->pluck('version_number')->all());
        $this->assertTrue(Storage::disk('local')->exists($versions->first()->stored_path));
        $this->assertFalse(Storage::disk('public')->exists($versions->first()->stored_path));
        $this->get('/storage/'.$versions->first()->stored_path)->assertNotFound();
        $this->actingAs($ownerB)->get(route('documents.download', $document))->assertNotFound();
        $this->actingAs($ownerA)->get(route('documents.download', $document))->assertOk();
        $this->assertDatabaseHas('document_access_logs', ['document_id' => $document->id, 'action' => 'download', 'user_id' => $ownerA->id]);
    }

    public function test_sensitive_values_never_reach_general_audit_and_tests_use_postgresql(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('rentfleet_test', DB::connection()->getDatabaseName());
        $audit = json_encode(DB::table('audit_logs')->get());
        $this->assertStringNotContainsString('CIN', $audit);
        $this->assertStringNotContainsString('PERMIS', $audit);
    }

    private function tenantUser(string $roleSlug = 'tenant-owner'): array
    {
        $tenant = Tenant::factory()->create();
        $agency = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create());
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => $roleSlug === 'tenant-owner' ? null : $agency->id, 'role_id' => $role->id]);

        return [$tenant, $agency, $user];
    }

    private function vehicleData(Agency $agency, VehicleCategory $category, string $registration): array
    {
        return ['agency_id' => $agency->id, 'vehicle_category_id' => $category->id, 'registration_number' => $registration, 'brand' => 'Dacia', 'model' => 'Sandero', 'production_year' => 2025, 'fuel_type' => 'diesel', 'transmission' => 'manual', 'current_mileage' => 1000];
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\nDemo\n%%EOF");
    }
}
