<?php

namespace Tests\Feature;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\CreateDriver;
use App\Actions\Documents\StorePrivateDocument;
use App\Actions\Finance\RecordDepositReceipt;
use App\Actions\Rentals\AcceptRentalContract;
use App\Actions\Rentals\ActivateRentalContract;
use App\Actions\Rentals\AttachContractVersionDocument;
use App\Actions\Rentals\CalculateReturnCharges;
use App\Actions\Rentals\CancelDraftRentalContract;
use App\Actions\Rentals\CompareVehicleInspections;
use App\Actions\Rentals\CompleteDepartureInspection;
use App\Actions\Rentals\CompleteReturnInspection;
use App\Actions\Rentals\CreateContractVersion;
use App\Actions\Rentals\CreateRentalContractFromReservation;
use App\Actions\Rentals\MarkContractReady;
use App\Actions\Rentals\MarkRentalReturned;
use App\Actions\Rentals\ReportVehicleDamage;
use App\Actions\Rentals\ReviewDamageResponsibility;
use App\Actions\Reservations\ConfirmReservation;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Vehicles\CreateVehicle;
use App\Enums\CustomerType;
use App\Enums\DamageResponsibility;
use App\Enums\DamageStatus;
use App\Enums\DocumentType;
use App\Enums\RentalContractStatus;
use App\Enums\VehicleBlockStatus;
use App\Enums\VehicleBlockType;
use App\Enums\VerificationStatus;
use App\Models\Agency;
use App\Models\ContractCharge;
use App\Models\PricingRule;
use App\Models\RentalContract;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VehicleBlock;
use App\Models\VehicleCategory;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Lot04RentalContractLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('documents.disk'));
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_confirmed_reservation_becomes_one_versioned_contract_and_keeps_the_same_block(): void
    {
        $f = $this->fixture();
        $blockId = $this->inTenant($f, fn () => $f['reservation']->activeVehicleBlock->id);
        $contract = $this->contract($f);

        $this->assertSame(RentalContractStatus::Draft, $contract->status);
        $this->assertSame('converted', $f['reservation']->refresh()->status->value);
        $this->assertSame(1, $this->inTenant($f, fn () => $contract->versions()->count()));
        $this->assertSame($f['reservation']->pricing_snapshot, $this->inTenant($f, fn () => $contract->currentVersion->pricing_snapshot));
        $this->assertMatchesRegularExpression('/^CTR-\d{4}-\d{6}$/', $contract->contract_number);
        $this->assertDatabaseHas('vehicle_blocks', ['id' => $blockId, 'rental_contract_id' => $contract->id, 'block_type' => 'contract', 'status' => 'active']);
        $this->expectException(ValidationException::class);
        $this->inTenant($f, fn () => app(CreateRentalContractFromReservation::class)->handle($f['reservation']->refresh(), $f['user']->id));
    }

    public function test_contract_numbers_are_tenant_scoped_and_unique(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $first = $this->contract($a);
        $second = $this->contract($b);

        $this->assertSame($first->contract_number, $second->contract_number);
        $this->assertNotSame($first->tenant_id, $second->tenant_id);
    }

    public function test_new_version_preserves_old_content_and_uses_a_stable_sha256_hash(): void
    {
        $f = $this->fixture();
        $contract = $this->contract($f);
        $first = $this->inTenant($f, fn () => $contract->currentVersion);
        $second = $this->inTenant($f, fn () => app(CreateContractVersion::class)->handle($contract, $f['user']->id, 'Avenant de test', ['clauses' => ['extra' => true]]));

        $this->assertSame(2, $second->version_number);
        $this->assertSame(64, strlen($second->content_hash));
        $this->assertNotSame($first->content_hash, $second->content_hash);
        $this->assertSame(1, $first->refresh()->version_number);
        $this->assertSame($second->id, $contract->refresh()->current_version_id);
    }

    public function test_post_acceptance_amendment_creates_a_new_ready_version_without_touching_the_locked_one(): void
    {
        $f = $this->fixture();
        $contract = $this->acceptedContract($f);
        $old = $this->inTenant($f, fn () => $contract->currentVersion);
        $new = $this->inTenant($f, fn () => app(CreateContractVersion::class)->handle($contract, $f['user']->id, 'Avenant explicite'));

        $this->assertNotNull($old->refresh()->locked_at);
        $this->assertSame(2, $new->version_number);
        $this->assertSame(RentalContractStatus::Ready, $contract->refresh()->status);
        $this->assertSame($new->id, $contract->current_version_id);
        $this->assertSame(RentalContractStatus::Accepted, $this->accept($f, $contract)->status);
    }

    public function test_acceptance_requires_documents_and_valid_driver_then_locks_the_exact_version(): void
    {
        $f = $this->fixture(withDocuments: false);
        $contract = $this->readyContract($f);
        try {
            $this->accept($f, $contract);
            $this->fail('Acceptation sans documents.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('documents', $exception->errors());
        }
        $this->documents($f);
        $accepted = $this->accept($f, $contract);

        $this->assertSame(RentalContractStatus::Accepted, $accepted->status);
        $this->assertNotNull($accepted->currentVersion->refresh()->locked_at);
        $this->assertDatabaseHas('contract_acceptances', ['rental_contract_id' => $contract->id, 'contract_version_id' => $contract->current_version_id, 'acceptance_method' => 'typed_name']);
        $this->assertDatabaseHas('contract_acceptances', ['rental_contract_id' => $contract->id, 'ip_address' => '127.0.0.1']);
        $this->expectException(QueryException::class);
        DB::table('contract_versions')->where('id', $contract->current_version_id)->update(['change_reason' => 'Mutation interdite']);
    }

    public function test_expired_driver_is_rejected_at_acceptance_and_sensitive_acceptance_values_are_not_audited(): void
    {
        $f = $this->fixture();
        $contract = $this->readyContract($f);
        $this->inTenant($f, fn () => $f['driver']->forceFill(['licence_expires_at' => $contract->expected_start_at->subDay()])->save());
        try {
            $this->accept($f, $contract);
            $this->fail('Permis expiré accepté.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('driver', $exception->errors());
        }
        $this->inTenant($f, fn () => $f['driver']->forceFill(['licence_expires_at' => today()->addYears(2)])->save());
        $this->accept($f, $contract);
        $audit = DB::table('audit_logs')->where('action', 'contract.accepted')->latest('id')->first();
        $this->assertStringNotContainsString('Client Signataire', json_encode($audit));
    }

    public function test_departure_inspection_is_required_for_activation_and_is_immutable_when_completed(): void
    {
        $f = $this->fixture();
        $contract = $this->acceptedContract($f);
        try {
            $this->inTenant($f, fn () => app(ActivateRentalContract::class)->handle($contract, $f['user']->id));
            $this->fail('Activation sans inspection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('inspection', $exception->errors());
        }
        $inspection = $this->departure($f, $contract);
        $this->deposit($f, $contract);
        $active = $this->inTenant($f, fn () => app(ActivateRentalContract::class)->handle($contract, $f['user']->id));
        $this->assertSame(RentalContractStatus::Active, $active->status);
        $this->assertSame(1010, $active->start_mileage);
        $this->expectException(QueryException::class);
        DB::table('inspection_items')->where('vehicle_inspection_id', $inspection->id)->update(['condition' => 'damaged']);
    }

    public function test_departure_requires_acceptance_and_rejects_mileage_below_vehicle_value(): void
    {
        $f = $this->fixture();
        $ready = $this->readyContract($f);
        try {
            $this->departure($f, $ready);
            $this->fail('Départ avant acceptation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
        $accepted = $this->accept($f, $ready);
        try {
            $this->inTenant($f, fn () => app(CompleteDepartureInspection::class)->handle($accepted, ['mileage' => 999, 'fuel_level' => '75.00', 'items' => $this->items()], $f['user']->id));
            $this->fail('Kilométrage de départ inférieur accepté.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('mileage', $exception->errors());
        }
    }

    public function test_return_rejects_lower_mileage_and_compares_items_without_float_amounts(): void
    {
        $f = $this->fixture();
        $contract = $this->activeContract($f);
        try {
            $this->returnInspection($f, $contract, 1009, '70.00');
            $this->fail('Kilométrage retour invalide accepté.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('mileage', $exception->errors());
        }
        $return = $this->returnInspection($f, $contract, 1210, '62.50', 'damaged');
        $departure = $this->inTenant($f, fn () => $contract->inspections()->where('inspection_type', 'departure')->firstOrFail());
        $comparison = $this->inTenant($f, fn () => app(CompareVehicleInspections::class)->handle($departure->load('items'), $return->load('items')));
        $this->assertSame(200, $comparison['mileage_delta']);
        $this->assertSame('-12.50', $comparison['fuel_delta']);
        $this->assertCount(1, $comparison['damage_candidates']);
        $this->assertSame(RentalContractStatus::ReturnPending, $contract->refresh()->status);
    }

    public function test_return_charges_use_exact_decimals_and_database_rejects_inconsistent_totals(): void
    {
        $f = $this->fixture();
        $contract = $this->returnPendingContract($f, 1510, '50.00');
        $result = $this->inTenant($f, fn () => app(CalculateReturnCharges::class)->handle($contract, ['cleaning_approved' => true, 'cleaning_amount' => '125.50']));

        $this->assertSame('25.00', $result['missing_fuel']);
        $this->assertNotEmpty($result['charges']);
        $this->assertFalse(is_float($result['charges'][0]->total_amount));
        $this->expectException(QueryException::class);
        DB::table('contract_charges')->insert(['tenant_id' => $f['tenant']->id, 'rental_contract_id' => $contract->id, 'charge_type' => 'other', 'description' => 'Incohérent', 'quantity' => '2.00', 'unit_amount' => '10.00', 'total_amount' => '99.00', 'status' => 'proposed', 'calculation_details' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_late_hours_and_extra_kilometres_are_rounded_and_calculated_without_float(): void
    {
        $f = $this->fixture();
        $contract = $this->activeContract($f);
        $this->inTenant($f, fn () => $contract->forceFill(['expected_start_at' => now()->subDay(), 'expected_return_at' => now()->subMinutes(90)])->save());
        $this->returnInspection($f, $contract, 1510, '75.00');
        $result = $this->inTenant($f, fn () => app(CalculateReturnCharges::class)->handle($contract->refresh()));

        $this->assertSame(2, $result['late_hours']);
        $this->assertSame(300, $result['extra_km']);
        $this->assertSame('0.00', $result['missing_fuel']);
        $this->assertFalse(is_float($result['charges'][0]->total_amount));
    }

    public function test_damage_never_assigns_responsibility_automatically_and_customer_charge_requires_human_review(): void
    {
        $f = $this->fixture();
        $contract = $this->returnPendingContract($f);
        $return = $this->inTenant($f, fn () => $contract->inspections()->where('inspection_type', 'return')->firstOrFail());
        $damage = $this->inTenant($f, fn () => app(ReportVehicleDamage::class)->handle($contract, ['return_inspection_id' => $return->id, 'description' => 'Rayure aile', 'severity' => 'minor', 'estimated_cost' => '300.00'], $f['user']->id));
        $this->assertSame(DamageResponsibility::Pending, $damage->responsibility);
        $this->assertSame(0, $damage->charges()->count());
        $reviewed = $this->inTenant($f, fn () => app(ReviewDamageResponsibility::class)->handle($damage, ['responsibility' => 'customer', 'status' => 'resolved', 'approved_cost' => '250.00', 'reason' => 'Constat contradictoire signé'], $f['user']->id));
        $this->assertSame(DamageStatus::Resolved, $reviewed->status);
        $this->assertDatabaseHas('contract_charges', ['damage_report_id' => $damage->id, 'total_amount' => '250.00', 'status' => 'proposed']);
        $this->assertDatabaseHas('damage_status_histories', ['damage_report_id' => $damage->id, 'responsibility' => 'customer']);
    }

    public function test_return_cannot_finish_with_pending_damage_or_undecided_charge(): void
    {
        $f = $this->fixture();
        $contract = $this->returnPendingContract($f);
        $return = $this->inTenant($f, fn () => $contract->inspections()->where('inspection_type', 'return')->firstOrFail());
        $damage = $this->inTenant($f, fn () => app(ReportVehicleDamage::class)->handle($contract, ['return_inspection_id' => $return->id, 'description' => 'Impact', 'severity' => 'moderate'], $f['user']->id));
        try {
            $this->inTenant($f, fn () => app(MarkRentalReturned::class)->handle($contract, [], $f['user']->id));
            $this->fail('Retour avec dommage en attente.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('damages', $exception->errors());
        }
        $this->inTenant($f, fn () => app(ReviewDamageResponsibility::class)->handle($damage, ['responsibility' => 'agency', 'status' => 'dismissed', 'reason' => 'Usure antérieure'], $f['user']->id));
        $this->inTenant($f, fn () => ContractCharge::create(['rental_contract_id' => $contract->id, 'charge_type' => 'other', 'description' => 'Test', 'quantity' => '1.00', 'unit_amount' => '10.00', 'total_amount' => '10.00', 'status' => 'proposed']));
        $this->expectException(ValidationException::class);
        $this->inTenant($f, fn () => app(MarkRentalReturned::class)->handle($contract, [], $f['user']->id));
    }

    public function test_final_return_updates_vehicle_totals_and_releases_only_its_own_block(): void
    {
        $f = $this->fixture();
        $contract = $this->returnPendingContract($f, 1310, '70.00');
        $charge = $this->inTenant($f, fn () => ContractCharge::create(['rental_contract_id' => $contract->id, 'charge_type' => 'other', 'description' => 'Frais validé', 'quantity' => '1.00', 'unit_amount' => '25.50', 'total_amount' => '25.50', 'status' => 'proposed']));
        $future = $this->inTenant($f, fn () => VehicleBlock::create(['agency_id' => $f['agency']->id, 'vehicle_id' => $f['vehicle']->id, 'block_type' => VehicleBlockType::Manual, 'starts_at' => $contract->expected_return_at->addDay(), 'ends_at' => $contract->expected_return_at->addDays(2), 'status' => VehicleBlockStatus::Active, 'created_by' => $f['user']->id]));
        $returned = $this->inTenant($f, fn () => app(MarkRentalReturned::class)->handle($contract, ['approved_charge_ids' => [$charge->id]], $f['user']->id));

        $this->assertSame(RentalContractStatus::Returned, $returned->status);
        $this->assertSame('25.50', $returned->additional_charges_total);
        $this->assertSame(1310, $f['vehicle']->refresh()->current_mileage);
        $this->assertSame(VehicleBlockStatus::Released, $this->inTenant($f, fn () => VehicleBlock::where('rental_contract_id', $contract->id)->firstOrFail()->status));
        $this->assertSame(VehicleBlockStatus::Active, $future->refresh()->status);
        $history = $this->inTenant($f, fn () => $contract->statusHistories()->get()->map(fn ($entry) => $entry->to_status->value)->all());
        $this->assertSame(['draft', 'ready', 'accepted', 'active', 'return_pending', 'returned'], $history);
    }

    public function test_only_confirmed_reservation_is_convertible_and_tenant_injection_is_rejected_by_http(): void
    {
        $f = $this->fixture();
        $this->inTenant($f, fn () => $f['reservation']->forceFill(['status' => 'draft'])->save());
        try {
            $this->contract($f);
            $this->fail('Réservation non confirmée convertie.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reservation', $exception->errors());
        }
        $this->inTenant($f, fn () => $f['reservation']->forceFill(['status' => 'confirmed'])->save());
        $this->actingAs($f['user'])->post(route('contracts.store', $f['reservation']), ['tenant_id' => 999])->assertSessionHasErrors('tenant_id');
        $this->assertSame(0, RentalContract::withoutGlobalScopes()->where('reservation_id', $f['reservation']->id)->count());
    }

    public function test_cross_tenant_contract_relation_is_rejected(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $contract = $this->acceptedContract($a);
        try {
            DB::table('rental_contracts')->where('id', $contract->id)->update(['customer_id' => $b['customer']->id]);
            $this->fail('Relation client cross-tenant acceptée.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->getCode());
        }
    }

    public function test_accepted_contract_cannot_be_physically_deleted(): void
    {
        $f = $this->fixture();
        $contract = $this->acceptedContract($f);
        $this->expectException(QueryException::class);
        DB::table('rental_contracts')->where('id', $contract->id)->delete();
    }

    public function test_inspection_photo_uses_private_document_storage_and_foreign_tenant_cannot_download_it(): void
    {
        Storage::fake('local');
        $a = $this->fixture();
        $contract = $this->acceptedContract($a);
        $inspection = $this->departure($a, $contract);
        $document = $this->inTenant($a, fn () => app(StorePrivateDocument::class)->handle($inspection, ['document_type' => DocumentType::InspectionPhoto, 'title' => 'Photo inspection', 'is_sensitive' => true], UploadedFile::fake()->createWithContent('inspection.pdf', "%PDF-1.4\nDemo\n%%EOF"), $a['user']->id));
        $version = $this->inTenant($a, fn () => $document->currentVersion);
        Storage::disk('local')->assertExists($version->stored_path);
        $this->assertStringNotContainsString('http', $version->stored_path);

        $b = $this->fixture();
        $this->actingAs($b['user'])->get(route('documents.download', $document))->assertNotFound();
    }

    public function test_draft_cancellation_releases_block_but_accepted_contract_and_closed_status_are_forbidden(): void
    {
        $f = $this->fixture();
        $draft = $this->contract($f);
        $cancelled = $this->inTenant($f, fn () => app(CancelDraftRentalContract::class)->handle($draft, 'Erreur de saisie', $f['user']->id));
        $this->assertSame(RentalContractStatus::Cancelled, $cancelled->status);
        $this->assertSame(VehicleBlockStatus::Cancelled, $this->inTenant($f, fn () => $draft->vehicleBlock->refresh()->status));

        $other = $this->fixture();
        $accepted = $this->acceptedContract($other);
        try {
            $this->inTenant($other, fn () => app(CancelDraftRentalContract::class)->handle($accepted, 'Interdit', $other['user']->id));
            $this->fail('Contrat accepté annulé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
        $this->expectException(QueryException::class);
        DB::table('rental_contracts')->where('id', $accepted->id)->update(['status' => 'closed']);
    }

    public function test_tenant_and_agency_authorization_is_enforced_and_suite_is_postgresql_only(): void
    {
        $a = $this->fixture('agency-manager');
        $b = $this->fixture('agency-manager');
        $contract = $this->contract($a);
        $foreign = $this->contract($b);

        $this->actingAs($a['user'])->get(route('contracts.index'))->assertOk()->assertSee($contract->contract_number)->assertViewHas('contracts', fn ($contracts) => $contracts->total() === 1);
        $this->actingAs($a['user'])->get(route('contracts.show', $foreign))->assertNotFound();
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('rentfleet_test', DB::connection()->getDatabaseName());
    }

    private function fixture(string $roleSlug = 'tenant-owner', bool $withDocuments = true): array
    {
        $tenant = Tenant::factory()->create();
        $agency = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create());
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => $roleSlug === 'tenant-owner' ? null : $agency->id, 'role_id' => $role->id]);
        $f = compact('tenant', 'agency', 'user');

        $f = $this->inTenant($f, function () use ($f) {
            $category = VehicleCategory::create(['code' => 'C-'.uniqid(), 'name' => 'Catégorie contrat', 'is_active' => true]);
            $vehicle = app(CreateVehicle::class)->handle(['agency_id' => $f['agency']->id, 'vehicle_category_id' => $category->id, 'registration_number' => 'RF-'.uniqid(), 'brand' => 'Dacia', 'model' => 'Duster', 'production_year' => 2025, 'fuel_type' => 'diesel', 'transmission' => 'manual', 'current_mileage' => 1000], $f['user']->id);
            $customer = app(CreateCustomer::class)->handle(['agency_id' => $f['agency']->id, 'customer_type' => CustomerType::Individual, 'first_name' => 'Client', 'last_name' => 'Contrat', 'verification_status' => VerificationStatus::Verified]);
            $driver = app(CreateDriver::class)->handle($customer, ['first_name' => 'Conducteur', 'last_name' => 'Valide', 'licence_number' => 'PERMIS-'.uniqid(), 'licence_expires_at' => today()->addYears(2), 'verification_status' => VerificationStatus::Verified, 'is_primary' => true]);
            $pricing = PricingRule::create(['agency_id' => null, 'vehicle_category_id' => $category->id, 'name' => 'Tarif contrat', 'daily_rate' => '400.00', 'deposit_amount' => '3000.00', 'included_km_per_day' => 200, 'extra_km_rate' => '2.50', 'late_hour_rate' => '75.00', 'minimum_days' => 1, 'maximum_days' => 30, 'valid_from' => today()->subYear(), 'priority' => 0, 'currency' => 'MAD', 'conditions' => [], 'is_active' => true, 'created_by' => $f['user']->id]);
            $start = CarbonImmutable::now()->addDays(3)->startOfHour();
            $reservation = app(CreateReservation::class)->handle(['agency_id' => $f['agency']->id, 'customer_id' => $customer->id, 'driver_id' => $driver->id, 'vehicle_category_id' => $category->id, 'vehicle_id' => $vehicle->id, 'starts_at' => $start, 'ends_at' => $start->addDay(), 'status' => 'draft'], $f['user']->id);
            app(ConfirmReservation::class)->handle($reservation, $f['user']->id);

            return [...$f, 'category' => $category, 'vehicle' => $vehicle, 'customer' => $customer, 'driver' => $driver, 'pricing' => $pricing, 'reservation' => $reservation->refresh()];
        });
        if ($withDocuments) {
            $this->documents($f);
        }

        return $f;
    }

    private function documents(array $f): void
    {
        $this->inTenant($f, function () use ($f) {
            $pdf = fn (string $name) => UploadedFile::fake()->createWithContent($name, "%PDF-1.4\nDocument contractuel fictif\n%%EOF");
            app(StorePrivateDocument::class)->handle($f['customer'], ['document_type' => DocumentType::CustomerIdentity, 'title' => 'Identité test', 'is_sensitive' => true], $pdf('identite-test.pdf'), $f['user']->id);
            app(StorePrivateDocument::class)->handle($f['driver'], ['document_type' => DocumentType::DrivingLicence, 'title' => 'Permis test', 'is_sensitive' => true], $pdf('permis-test.pdf'), $f['user']->id);
        });
    }

    private function contract(array $f): RentalContract
    {
        return $this->inTenant($f, fn () => app(CreateRentalContractFromReservation::class)->handle($f['reservation'], $f['user']->id));
    }

    private function readyContract(array $f): RentalContract
    {
        $contract = $this->contract($f);

        return $this->inTenant($f, fn () => app(MarkContractReady::class)->handle($contract, $f['user']->id));
    }

    private function accept(array $f, RentalContract $contract): RentalContract
    {
        return $this->inTenant($f, function () use ($f, $contract) {
            if (! $contract->currentVersion()->value('document_id')) {
                app(AttachContractVersionDocument::class)->handle($contract, UploadedFile::fake()->createWithContent('contrat.pdf', "%PDF-1.4\nContrat fictif\n%%EOF"), $f['user']->id);
            }

            return app(AcceptRentalContract::class)->handle($contract, ['accepted_by_name' => 'Client Signataire', 'acceptance_method' => 'typed_name', 'ip_address' => '127.0.0.1', 'user_agent' => 'PHPUnit'], $f['user']->id);
        });
    }

    private function acceptedContract(array $f): RentalContract
    {
        return $this->accept($f, $this->readyContract($f));
    }

    private function departure(array $f, RentalContract $contract)
    {
        return $this->inTenant($f, fn () => app(CompleteDepartureInspection::class)->handle($contract, ['mileage' => 1010, 'fuel_level' => '75.00', 'items' => $this->items()], $f['user']->id));
    }

    private function activeContract(array $f): RentalContract
    {
        $contract = $this->acceptedContract($f);
        $this->departure($f, $contract);
        $this->deposit($f, $contract);

        return $this->inTenant($f, fn () => app(ActivateRentalContract::class)->handle($contract, $f['user']->id));
    }

    private function deposit(array $f, RentalContract $contract): void
    {
        if ($contract->deposit_required !== '0.00') {
            $this->inTenant($f, fn () => app(RecordDepositReceipt::class)->handle($contract, $contract->deposit_required, 'lot04-departure-'.$contract->id, $f['user']->id));
        }
    }

    private function returnInspection(array $f, RentalContract $contract, int $mileage, string $fuel, string $body = 'good')
    {
        return $this->inTenant($f, fn () => app(CompleteReturnInspection::class)->handle($contract, ['mileage' => $mileage, 'fuel_level' => $fuel, 'items' => $this->items($body)], $f['user']->id));
    }

    private function returnPendingContract(array $f, int $mileage = 1210, string $fuel = '70.00'): RentalContract
    {
        $contract = $this->activeContract($f);
        $this->returnInspection($f, $contract, $mileage, $fuel);

        return $contract->refresh();
    }

    private function items(string $body = 'good'): array
    {
        return [['item_code' => 'body', 'label' => 'Carrosserie', 'condition' => $body], ['item_code' => 'interior', 'label' => 'Habitacle', 'condition' => 'good']];
    }

    private function inTenant(array $f, callable $callback): mixed
    {
        return app(TenantContext::class)->run($f['tenant'], $callback, $f['agency']->id);
    }
}
