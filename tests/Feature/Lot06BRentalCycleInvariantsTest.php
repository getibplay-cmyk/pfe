<?php

namespace Tests\Feature;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\CreateDriver;
use App\Actions\Documents\AddDocumentVersion;
use App\Actions\Documents\StorePrivateDocument;
use App\Actions\Finance\RecordDepositReceipt;
use App\Actions\Finance\RefundDeposit;
use App\Actions\Insurance\ApproveInsuranceClaim;
use App\Actions\Insurance\CloseInsuranceClaim;
use App\Actions\Insurance\CreateInsuranceClaim;
use App\Actions\Insurance\RejectInsuranceClaim;
use App\Actions\Insurance\SettleInsuranceClaim;
use App\Actions\Insurance\StartInsuranceClaimReview;
use App\Actions\Rentals\AcceptRentalContract;
use App\Actions\Rentals\ActivateRentalContract;
use App\Actions\Rentals\AttachContractVersionDocument;
use App\Actions\Rentals\CompleteDepartureInspection;
use App\Actions\Rentals\CreateRentalContractFromReservation;
use App\Actions\Rentals\MarkContractReady;
use App\Actions\Reservations\ConfirmReservation;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\UpdateDraftReservation;
use App\Actions\Vehicles\CreateVehicle;
use App\Enums\CustomerType;
use App\Enums\DocumentType;
use App\Enums\InsuranceClaimStatus;
use App\Enums\RentalContractStatus;
use App\Enums\VerificationStatus;
use App\Models\Agency;
use App\Models\Document;
use App\Models\InsuranceClaim;
use App\Models\InsuranceCompany;
use App\Models\InsurancePolicy;
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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Lot06BRentalCycleInvariantsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-01 10:00:00', config('app.timezone')));
        Storage::fake(config('documents.disk'));
        $this->seed(RolesPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_confirmation_requires_active_verified_parties_same_agency_and_licence_valid_for_the_whole_period(): void
    {
        $f = $this->fixture();
        $reservation = $this->reservation($f);

        $this->inTenant($f, fn () => $f['customer']->delete());
        $this->expectValidation(fn () => $this->confirm($f, $reservation), 'customer_id');
        $this->inTenant($f, fn () => $f['customer']->restore());

        $this->inTenant($f, fn () => $f['customer']->forceFill(['verification_status' => VerificationStatus::Pending])->save());
        $this->expectValidation(fn () => $this->confirm($f, $reservation), 'customer_id');
        $this->inTenant($f, fn () => $f['customer']->forceFill(['verification_status' => VerificationStatus::Verified])->save());

        $this->inTenant($f, fn () => $f['driver']->forceFill(['verification_status' => VerificationStatus::Pending])->save());
        $this->expectValidation(fn () => $this->confirm($f, $reservation), 'driver_id');
        $this->inTenant($f, fn () => $f['driver']->forceFill(['verification_status' => VerificationStatus::Verified, 'licence_expires_at' => $reservation->starts_at->toDateString()])->save());
        $this->expectValidation(fn () => $this->confirm($f, $reservation), 'driver_id');

        $otherAgency = $this->inTenant($f, fn () => Agency::factory()->create());
        [$otherCustomer, $otherDriver] = app(TenantContext::class)->run($f['tenant'], function () use ($otherAgency) {
            $customer = app(CreateCustomer::class)->handle(['agency_id' => $otherAgency->id, 'customer_type' => CustomerType::Individual, 'first_name' => 'Autre', 'last_name' => 'Agence', 'verification_status' => VerificationStatus::Verified]);
            $driver = app(CreateDriver::class)->handle($customer, ['first_name' => 'Autre', 'last_name' => 'Conducteur', 'licence_number' => 'L06B-OTHER-'.uniqid(), 'licence_expires_at' => today()->addYear(), 'verification_status' => VerificationStatus::Verified, 'is_primary' => true]);

            return [$customer, $driver];
        });
        $foreignAgencyReservation = $this->inTenant($f, fn () => app(CreateReservation::class)->handle([...$this->reservationData($f), 'customer_id' => $otherCustomer->id, 'driver_id' => $otherDriver->id], $f['user']->id));
        $this->expectValidation(fn () => $this->confirm($f, $foreignAgencyReservation), 'customer_id');

        $this->inTenant($f, fn () => $f['driver']->forceFill(['licence_expires_at' => $reservation->ends_at->toDateString()])->save());
        $confirmed = $this->confirm($f, $reservation);
        $this->assertSame('confirmed', $confirmed->status->value);
    }

    public function test_past_period_is_rejected_on_http_creation_action_update_and_confirmation_while_future_period_succeeds(): void
    {
        $f = $this->fixture();
        $pastStart = CarbonImmutable::now(config('app.timezone'))->subHour();
        $pastData = [...$this->reservationData($f), 'starts_at' => $pastStart, 'ends_at' => $pastStart->addHour()];

        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(CreateReservation::class)->handle($pastData, $f['user']->id)), 'starts_at');
        $this->actingAs($f['user'])->post(route('reservations.store'), $pastData)->assertSessionHasErrors('starts_at');

        $draft = $this->reservation($f);
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(UpdateDraftReservation::class)->handle($draft, $pastData)), 'starts_at');

        $this->inTenant($f, fn () => $draft->forceFill(['starts_at' => $pastStart, 'ends_at' => $pastStart->addHour()])->save());
        $this->expectValidation(fn () => $this->confirm($f, $draft), 'starts_at');

        $future = $this->reservation($f, CarbonImmutable::now(config('app.timezone'))->addDays(5));
        $updatedStart = CarbonImmutable::now(config('app.timezone'))->addDays(6);
        $updated = $this->inTenant($f, fn () => app(UpdateDraftReservation::class)->handle($future, [...$this->reservationData($f, $updatedStart), 'notes' => 'Période future modifiée']));
        $this->assertSame($updatedStart->toIso8601String(), $updated->starts_at->toIso8601String());
        $this->assertSame('confirmed', $this->confirm($f, $updated)->status->value);
    }

    public function test_contract_acceptance_requires_current_private_file_and_matching_sha256(): void
    {
        $f = $this->fixture();
        $contract = $this->readyContract($f);
        $this->documents($f);

        $this->expectValidation(fn () => $this->accept($f, $contract), 'documents');

        $contractDocument = $this->inTenant($f, function () use ($f, $contract) {
            $document = Document::create(['agency_id' => $f['agency']->id, 'documentable_type' => $contract->getMorphClass(), 'documentable_id' => $contract->id, 'document_type' => DocumentType::ContractAcceptance, 'title' => 'Contrat sans fichier', 'is_sensitive' => true, 'created_by' => $f['user']->id]);
            $contract->currentVersion()->firstOrFail()->forceFill(['document_id' => $document->id])->save();

            return $document;
        });
        $this->expectValidation(fn () => $this->accept($f, $contract), 'documents');

        $contents = "%PDF-1.4\nContrat privé Lot 06B\n%%EOF";
        $version = $this->inTenant($f, fn () => app(AddDocumentVersion::class)->handle($contractDocument, UploadedFile::fake()->createWithContent('contrat.pdf', $contents), $f['user']->id));
        Storage::disk(config('documents.disk'))->delete($version->stored_path);
        $this->expectValidation(fn () => $this->accept($f, $contract), 'documents');

        Storage::disk(config('documents.disk'))->put($version->stored_path, $contents);
        $this->inTenant($f, fn () => $version->forceFill(['sha256' => str_repeat('0', 64)])->save());
        $this->expectValidation(fn () => $this->accept($f, $contract), 'documents');

        $this->inTenant($f, fn () => $version->forceFill(['sha256' => hash('sha256', $contents)])->save());
        $accepted = $this->accept($f, $contract);
        $this->assertSame(RentalContractStatus::Accepted, $accepted->status);
        Storage::disk(config('documents.disk'))->assertExists($version->stored_path);
        $this->assertStringNotContainsString('public', $version->stored_path);
    }

    public function test_activation_requires_effective_deposit_and_failure_is_atomic(): void
    {
        $f = $this->fixture();
        $contract = $this->acceptedContract($f);
        $inspection = $this->departure($f, $contract);
        $block = $this->inTenant($f, fn () => $contract->vehicleBlock);
        $before = $this->inTenant($f, fn () => [
            'contract_status' => $contract->refresh()->status,
            'vehicle_status' => $f['vehicle']->refresh()->operational_status,
            'vehicle_mileage' => $f['vehicle']->current_mileage,
            'inspection_status' => $inspection->refresh()->status,
            'block_status' => $block->refresh()->status,
        ]);

        $this->expectValidation(fn () => $this->activate($f, $contract), 'deposit');
        $after = $this->inTenant($f, fn () => [
            'contract_status' => $contract->refresh()->status,
            'vehicle_status' => $f['vehicle']->refresh()->operational_status,
            'vehicle_mileage' => $f['vehicle']->current_mileage,
            'inspection_status' => $inspection->refresh()->status,
            'block_status' => $block->refresh()->status,
        ]);
        $this->assertSame($before, $after);

        $this->receiveDeposit($f, $contract, '299.00', 'lot06b-partial');
        $this->expectValidation(fn () => $this->activate($f, $contract), 'deposit');
        $this->receiveDeposit($f, $contract, '1.00', 'lot06b-complete');
        $this->assertSame(RentalContractStatus::Active, $this->activate($f, $contract)->status);

        $refundedFixture = $this->fixture();
        $refunded = $this->acceptedContract($refundedFixture);
        $this->departure($refundedFixture, $refunded);
        $this->receiveDeposit($refundedFixture, $refunded, '300.00', 'lot06b-refunded-receipt');
        $this->inTenant($refundedFixture, fn () => app(RefundDeposit::class)->handle($refunded, '300.00', 'lot06b-refunded', $refundedFixture['user']->id));
        $this->expectValidation(fn () => $this->activate($refundedFixture, $refunded), 'deposit');

        $none = $this->fixture();
        $noDepositContract = $this->contract($none);
        $this->inTenant($none, fn () => $noDepositContract->forceFill(['deposit_required' => '0.00'])->save());
        $this->documents($none);
        $this->inTenant($none, fn () => app(MarkContractReady::class)->handle($noDepositContract, $none['user']->id));
        $this->accept($none, $noDepositContract);
        $this->departure($none, $noDepositContract);
        $this->assertSame(RentalContractStatus::Active, $this->activate($none, $noDepositContract)->status);
    }

    public function test_claim_creation_transitions_history_audit_and_database_state_machine(): void
    {
        $f = $this->fixture();
        $policy = $this->policy($f);
        $this->actingAs($f['user']);

        $advanced = [...$this->claimData($f, $policy), 'status' => 'approved', 'approved_amount' => '100.00'];
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(CreateInsuranceClaim::class)->handle($advanced, $f['user']->id)), 'status');
        $this->post(route('insurance.claims.store'), $advanced)->assertSessionHasErrors(['status', 'approved_amount']);

        $claim = $this->createClaim($f, $policy);
        $this->assertSame(InsuranceClaimStatus::Reported, $claim->status);
        $this->assertNull($claim->approved_amount);
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(ApproveInsuranceClaim::class)->handle($claim, '100.00', $f['user']->id)), 'status');

        $this->get(route('insurance.index'))->assertOk()->assertSee($claim->claim_number);
        $this->post(route('insurance.claims.submit', $claim), ['note' => 'Soumission humaine'])->assertRedirect();
        $claim = $this->inTenant($f, fn () => $claim->refresh());
        $this->assertSame(InsuranceClaimStatus::Submitted, $claim->status);
        $claim = $this->inTenant($f, fn () => app(StartInsuranceClaimReview::class)->handle($claim, $f['user']->id, 'Instruction humaine'));
        $claim = $this->inTenant($f, fn () => app(ApproveInsuranceClaim::class)->handle($claim, '400.00', $f['user']->id, 'Décision humaine'));
        $claim = $this->inTenant($f, fn () => app(SettleInsuranceClaim::class)->handle($claim, '350.00', $f['user']->id, 'Règlement assureur'));
        $claim = $this->inTenant($f, fn () => app(CloseInsuranceClaim::class)->handle($claim, $f['user']->id, 'Dossier terminé'));

        $this->assertSame(InsuranceClaimStatus::Closed, $claim->status);
        $this->assertSame('400.00', $claim->approved_amount);
        $this->assertSame('350.00', $claim->settled_amount);
        $this->assertSame(6, $this->inTenant($f, fn () => $claim->statusHistories()->count()));
        $this->assertSame(6, DB::table('audit_logs')->where('auditable_type', InsuranceClaim::class)->where('auditable_id', $claim->id)->count());

        $rejected = $this->createClaim($f, $policy);
        $this->inTenant($f, fn () => app(StartInsuranceClaimReview::class)->handle($rejected, $f['user']->id));
        $rejected = $this->inTenant($f, fn () => app(RejectInsuranceClaim::class)->handle($rejected, $f['user']->id));
        $this->assertSame(InsuranceClaimStatus::Rejected, $rejected->status);
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(SettleInsuranceClaim::class)->handle($rejected, '10.00', $f['user']->id)), 'status');

        $direct = $this->createClaim($f, $policy);
        $this->assertConstraintViolation(fn () => DB::table('insurance_claims')->where('id', $direct->id)->update(['status' => 'closed']));
        $historyId = $this->inTenant($f, fn () => $claim->statusHistories()->value('id'));
        $this->assertConstraintViolation(fn () => DB::table('insurance_claim_status_histories')->where('id', $historyId)->update(['note' => 'Mutation interdite']));
    }

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create();
        $agency = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create());
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => null, 'role_id' => Role::where('slug', 'tenant-owner')->value('id')]);
        $fixture = compact('tenant', 'agency', 'user');

        return $this->inTenant($fixture, function () use ($fixture) {
            $category = VehicleCategory::create(['code' => 'L06B-'.uniqid(), 'name' => 'Catégorie Lot 06B', 'is_active' => true]);
            $vehicle = app(CreateVehicle::class)->handle(['agency_id' => $fixture['agency']->id, 'vehicle_category_id' => $category->id, 'registration_number' => 'L06B-'.uniqid(), 'brand' => 'Dacia', 'model' => 'Duster', 'production_year' => 2025, 'fuel_type' => 'diesel', 'transmission' => 'manual', 'current_mileage' => 1000], $fixture['user']->id);
            $customer = app(CreateCustomer::class)->handle(['agency_id' => $fixture['agency']->id, 'customer_type' => CustomerType::Individual, 'first_name' => 'Client', 'last_name' => 'Lot 06B', 'verification_status' => VerificationStatus::Verified]);
            $driver = app(CreateDriver::class)->handle($customer, ['first_name' => 'Conducteur', 'last_name' => 'Lot 06B', 'licence_number' => 'L06B-'.uniqid(), 'licence_expires_at' => today()->addYear(), 'verification_status' => VerificationStatus::Verified, 'is_primary' => true]);
            PricingRule::create(['agency_id' => null, 'vehicle_category_id' => $category->id, 'name' => 'Tarif Lot 06B', 'daily_rate' => '400.00', 'deposit_amount' => '300.00', 'included_km_per_day' => 200, 'extra_km_rate' => '2.50', 'late_hour_rate' => '75.00', 'minimum_days' => 1, 'maximum_days' => 30, 'valid_from' => today()->subYear(), 'priority' => 0, 'currency' => 'MAD', 'conditions' => [], 'is_active' => true, 'created_by' => $fixture['user']->id]);

            return [...$fixture, 'category' => $category, 'vehicle' => $vehicle, 'customer' => $customer, 'driver' => $driver];
        });
    }

    private function reservation(array $f, ?CarbonImmutable $start = null)
    {
        return $this->inTenant($f, fn () => app(CreateReservation::class)->handle($this->reservationData($f, $start), $f['user']->id));
    }

    private function reservationData(array $f, ?CarbonImmutable $start = null): array
    {
        $start ??= CarbonImmutable::now(config('app.timezone'))->addDays(2);

        return ['agency_id' => $f['agency']->id, 'customer_id' => $f['customer']->id, 'driver_id' => $f['driver']->id, 'vehicle_category_id' => $f['category']->id, 'vehicle_id' => $f['vehicle']->id, 'starts_at' => $start, 'ends_at' => $start->addDays(2), 'status' => 'draft'];
    }

    private function confirm(array $f, $reservation)
    {
        return $this->inTenant($f, fn () => app(ConfirmReservation::class)->handle($reservation, $f['user']->id));
    }

    private function contract(array $f): RentalContract
    {
        $reservation = $this->reservation($f);
        $this->confirm($f, $reservation);

        return $this->inTenant($f, fn () => app(CreateRentalContractFromReservation::class)->handle($reservation, $f['user']->id));
    }

    private function readyContract(array $f): RentalContract
    {
        $contract = $this->contract($f);
        $this->inTenant($f, fn () => app(MarkContractReady::class)->handle($contract, $f['user']->id));

        return $contract->refresh();
    }

    private function acceptedContract(array $f): RentalContract
    {
        $contract = $this->contract($f);
        $this->documents($f);
        $this->attachContractDocument($f, $contract);
        $this->inTenant($f, fn () => app(MarkContractReady::class)->handle($contract, $f['user']->id));

        return $this->accept($f, $contract);
    }

    private function accept(array $f, RentalContract $contract): RentalContract
    {
        return $this->inTenant($f, fn () => app(AcceptRentalContract::class)->handle($contract, ['accepted_by_name' => 'Client Lot 06B', 'acceptance_method' => 'typed_name'], $f['user']->id));
    }

    private function documents(array $f): void
    {
        $this->storeDocument($f, $f['customer'], DocumentType::CustomerIdentity, 'identite-lot06b.pdf');
        $this->storeDocument($f, $f['driver'], DocumentType::DrivingLicence, 'permis-lot06b.pdf');
    }

    private function attachContractDocument(array $f, RentalContract $contract): void
    {
        $this->inTenant($f, fn () => app(AttachContractVersionDocument::class)->handle($contract, UploadedFile::fake()->createWithContent('contrat-lot06b.pdf', "%PDF-1.4\nContrat Lot 06B\n%%EOF"), $f['user']->id));
    }

    private function storeDocument(array $f, $owner, DocumentType $type, string $name): Document
    {
        return $this->inTenant($f, fn () => app(StorePrivateDocument::class)->handle($owner, ['document_type' => $type, 'title' => $type->value, 'is_sensitive' => true], UploadedFile::fake()->createWithContent($name, "%PDF-1.4\nLot 06B\n%%EOF"), $f['user']->id));
    }

    private function departure(array $f, RentalContract $contract)
    {
        return $this->inTenant($f, fn () => app(CompleteDepartureInspection::class)->handle($contract, ['mileage' => 1010, 'fuel_level' => '75.00', 'items' => [['item_code' => 'body', 'label' => 'Carrosserie', 'condition' => 'good']]], $f['user']->id));
    }

    private function activate(array $f, RentalContract $contract): RentalContract
    {
        return $this->inTenant($f, fn () => app(ActivateRentalContract::class)->handle($contract, $f['user']->id));
    }

    private function receiveDeposit(array $f, RentalContract $contract, string $amount, string $key): void
    {
        $this->inTenant($f, fn () => app(RecordDepositReceipt::class)->handle($contract, $amount, $key, $f['user']->id));
    }

    private function policy(array $f): InsurancePolicy
    {
        return $this->inTenant($f, function () use ($f) {
            $company = InsuranceCompany::create(['name' => 'Assureur Lot 06B', 'is_active' => true]);
            $policy = new InsurancePolicy(['agency_id' => $f['agency']->id, 'vehicle_id' => $f['vehicle']->id, 'insurance_company_id' => $company->id, 'policy_type' => 'comprehensive', 'starts_at' => today()->subMonth(), 'ends_at' => today()->addYear(), 'premium_amount' => '1000.00', 'deductible_amount' => '500.00', 'currency' => 'MAD', 'status' => 'active']);
            $policy->setPolicyNumber('L06B-'.uniqid())->save();

            return $policy;
        });
    }

    private function claimData(array $f, InsurancePolicy $policy): array
    {
        return ['agency_id' => $f['agency']->id, 'insurance_policy_id' => $policy->id, 'reported_at' => now(), 'claimed_amount' => '500.00', 'notes' => 'Décision exclusivement humaine.'];
    }

    private function createClaim(array $f, InsurancePolicy $policy): InsuranceClaim
    {
        return $this->inTenant($f, fn () => app(CreateInsuranceClaim::class)->handle($this->claimData($f, $policy), $f['user']->id));
    }

    private function inTenant(array $f, callable $callback): mixed
    {
        return app(TenantContext::class)->run($f['tenant'], $callback, $f['agency']->id);
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
            $this->fail('Contrainte PostgreSQL attendue.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
    }
}
