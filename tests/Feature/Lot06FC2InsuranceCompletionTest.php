<?php

namespace Tests\Feature;

use App\Actions\Documents\ArchiveDocument;
use App\Actions\Documents\StorePrivateDocument;
use App\Actions\Insurance\ActivateInsurancePolicy;
use App\Actions\Insurance\ApproveInsuranceClaim;
use App\Actions\Insurance\AttachDemoInsurancePolicyProof;
use App\Actions\Insurance\CancelInsurancePolicy;
use App\Actions\Insurance\CloseInsuranceClaim;
use App\Actions\Insurance\CreateInsuranceClaim;
use App\Actions\Insurance\CreateInsuranceCompany;
use App\Actions\Insurance\CreateInsuranceCoverage;
use App\Actions\Insurance\CreateInsurancePolicy;
use App\Actions\Insurance\DeactivateInsuranceCompany;
use App\Actions\Insurance\ReactivateInsuranceCompany;
use App\Actions\Insurance\RenewInsurancePolicy;
use App\Actions\Insurance\SettleInsuranceClaim;
use App\Actions\Insurance\StartInsuranceClaimReview;
use App\Actions\Insurance\UpdateDraftInsurancePolicy;
use App\Actions\Insurance\UpdateInsuranceCompany;
use App\Actions\Vehicles\CreateVehicle;
use App\Enums\DocumentType;
use App\Enums\InsuranceClaimStatus;
use App\Enums\InsurancePolicyStatus;
use App\Models\Agency;
use App\Models\InsuranceCompany;
use App\Models\InsurancePolicy;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VehicleCategory;
use App\Support\Insurance\InsuranceCompanyTransition;
use App\Support\Insurance\InsurancePolicyTransition;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Lot06FC2InsuranceCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('documents.disk'));
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_company_cycle_is_audited_locked_and_deactivation_with_open_policy_is_refused(): void
    {
        $f = $this->fixture();
        $company = $this->inTenant($f, fn () => app(CreateInsuranceCompany::class)->handle(['name' => 'Assureur C2', 'email' => 'assureur@example.test']));
        $this->assertDatabaseHas('audit_logs', ['action' => 'insurance.company.created', 'auditable_id' => $company->id]);
        $this->inTenant($f, fn () => app(UpdateInsuranceCompany::class)->handle($company, ['name' => 'Assureur C2 modifié', 'email' => 'contact@example.test']));
        $draft = $this->draft($f, $company);
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(DeactivateInsuranceCompany::class)->handle($company, $f['owner']->id)), 'company');
        $this->inTenant($f, fn () => app(CancelInsurancePolicy::class)->handle($draft, 'Brouillon abandonné', $f['owner']->id));
        $company = $this->inTenant($f, fn () => app(DeactivateInsuranceCompany::class)->handle($company, $f['owner']->id));
        $this->assertFalse($company->is_active);
        $company = $this->inTenant($f, fn () => app(ReactivateInsuranceCompany::class)->handle($company));
        $this->assertTrue($company->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'insurance.company.reactivated', 'auditable_id' => $company->id]);
        $this->expectConstraint(fn () => DB::table('insurance_companies')->where('id', $company->id)->delete(), '23514');
    }

    public function test_policy_is_forced_draft_and_activation_requires_coverage_private_proof_and_active_company(): void
    {
        $f = $this->fixture();
        $company = $this->company($f);
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(CreateInsurancePolicy::class)->handle([...$this->policyData($f, $company), 'status' => 'active'], $f['owner']->id)), 'status');
        $policy = $this->draft($f, $company);
        $this->assertSame(InsurancePolicyStatus::Draft, $policy->status);
        $this->assertNotSame('C2-POLICY-SECRET-1234', DB::table('insurance_policies')->where('id', $policy->id)->value('policy_number_encrypted'));
        $this->assertStringEndsWith('1234', $policy->maskedPolicyNumber());
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(ActivateInsurancePolicy::class)->handle($policy, $f['owner']->id)), 'coverage');
        $this->inTenant($f, fn () => app(CreateInsuranceCoverage::class)->handle($policy, $this->coverageData()));
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(ActivateInsurancePolicy::class)->handle($policy, $f['owner']->id)), 'document');

        InsuranceCompanyTransition::allow(true, false);
        $this->inTenant($f, fn () => $company->forceFill(['is_active' => false, 'deactivated_at' => now(), 'deactivated_by' => $f['owner']->id])->save());
        $this->inTenant($f, fn () => app(AttachDemoInsurancePolicyProof::class)->handle($policy, $f['owner']->id, 'insurance.policy.document.seeded'));
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(ActivateInsurancePolicy::class)->handle($policy, $f['owner']->id)), 'insurance_company_id');
        InsuranceCompanyTransition::allow(false, true);
        $this->inTenant($f, fn () => $company->forceFill(['is_active' => true, 'deactivated_at' => null, 'deactivated_by' => null])->save());
        $active = $this->inTenant($f, fn () => app(ActivateInsurancePolicy::class)->handle($policy, $f['owner']->id));
        $this->assertSame(InsurancePolicyStatus::Active, $active->status);
        $this->assertNotNull($active->activated_at);
        $this->assertDatabaseHas('insurance_policy_status_histories', ['insurance_policy_id' => $active->id, 'from_status' => 'draft', 'to_status' => 'active']);
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(UpdateDraftInsurancePolicy::class)->handle($active, $this->policyData($f, $company))), 'policy');
    }

    public function test_postgresql_rejects_overlap_terminal_mutation_coverage_mutation_and_history_rewrite(): void
    {
        $f = $this->fixture();
        $company = $this->company($f);
        $first = $this->activePolicy($f, $company);
        $second = $this->draft($f, $company, 'C2-OVERLAP-5678');
        $this->inTenant($f, function () use ($f, $second): void {
            app(CreateInsuranceCoverage::class)->handle($second, $this->coverageData('Vol'));
            app(AttachDemoInsurancePolicyProof::class)->handle($second, $f['owner']->id, 'insurance.policy.document.seeded');
        });
        try {
            DB::transaction(function () use ($f, $second): void {
                InsurancePolicyTransition::allow('draft', 'active');
                DB::table('insurance_policies')->where('id', $second->id)->update(['status' => 'active', 'activated_at' => now(), 'activated_by' => $f['owner']->id, 'updated_at' => now()]);
            });
            $this->fail('La contrainte GiST aurait dû refuser le chevauchement.');
        } catch (QueryException $exception) {
            $this->assertSame('23P01', $exception->getCode());
        }

        $cancelled = $this->inTenant($f, fn () => app(CancelInsurancePolicy::class)->handle($first, 'Fin anticipée', $f['owner']->id));
        $this->expectConstraint(fn () => DB::table('insurance_policies')->where('id', $cancelled->id)->update(['premium_amount' => '1.00']), '23514');
        $historyId = DB::table('insurance_policy_status_histories')->where('insurance_policy_id', $cancelled->id)->value('id');
        $this->expectConstraint(fn () => DB::table('insurance_policy_status_histories')->where('id', $historyId)->update(['reason' => 'altéré']), '23514');
        $coverageId = DB::table('insurance_policy_coverages')->where('insurance_policy_id', $cancelled->id)->value('id');
        $this->expectConstraint(fn () => DB::table('insurance_policy_coverages')->where('id', $coverageId)->update(['label' => 'altérée']), '23514');
    }

    public function test_renewal_preserves_old_policy_and_expiration_command_is_idempotent_and_scheduled(): void
    {
        $f = $this->fixture();
        $company = $this->company($f);
        $old = $this->activePolicy($f, $company, today()->subYear(), today()->subDay());
        $before = $old->getAttributes();
        $renewal = $this->inTenant($f, fn () => app(RenewInsurancePolicy::class)->handle($old, ['policy_number' => 'C2-RENEW-9999', 'starts_at' => today(), 'ends_at' => today()->addYear(), 'copy_coverages' => true], $f['owner']->id));
        $this->assertSame(InsurancePolicyStatus::Draft, $renewal->status);
        $this->assertSame($old->id, $renewal->renewed_from_id);
        $this->assertSame(1, $this->inTenant($f, fn () => $renewal->coverages()->count()));
        $this->assertSame($before, $this->inTenant($f, fn () => $old->fresh()->getAttributes()));
        $this->artisan('insurance:expire-policies')->assertSuccessful();
        $this->assertSame(InsurancePolicyStatus::Expired, $old->refresh()->status);
        $historyCount = $this->inTenant($f, fn () => $old->statusHistories()->count());
        $this->artisan('insurance:expire-policies')->assertSuccessful();
        $this->assertSame($historyCount, $this->inTenant($f, fn () => $old->statusHistories()->count()));
        $this->assertTrue(collect(app(Schedule::class)->events())->contains(fn ($event) => str_contains((string) $event->command, 'insurance:expire-policies')));
    }

    public function test_claim_incident_integrity_existing_review_convention_and_full_human_cycle_are_preserved(): void
    {
        $f = $this->fixture();
        $policy = $this->activePolicy($f, $this->company($f));
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(CreateInsuranceClaim::class)->handle($this->claimData($f, $policy, today()->subYears(2)), $f['owner']->id)), 'incident_at');
        $claim = $this->inTenant($f, fn () => app(CreateInsuranceClaim::class)->handle($this->claimData($f, $policy, now()->subHour()), $f['owner']->id));
        $claim = $this->inTenant($f, fn () => app(StartInsuranceClaimReview::class)->handle($claim, $f['owner']->id, 'Revue humaine'));
        $this->assertSame(InsuranceClaimStatus::UnderReview, $claim->status);
        $this->assertNull($claim->reviewed_at);
        $claim = $this->inTenant($f, fn () => app(ApproveInsuranceClaim::class)->handle($claim, '400.00', $f['owner']->id, 'Décision humaine'));
        $claim = $this->inTenant($f, fn () => app(SettleInsuranceClaim::class)->handle($claim, '350.00', $f['owner']->id, 'Règlement constaté'));
        $proof = $this->inTenant($f, fn () => app(StorePrivateDocument::class)->handle($claim, ['document_type' => DocumentType::InsuranceClaimSettlementProof, 'title' => 'Preuve fictive de règlement', 'is_sensitive' => true], $this->pdf('reglement.pdf'), $f['owner']->id));
        $claim = $this->inTenant($f, fn () => app(CloseInsuranceClaim::class)->handle($claim, $f['owner']->id, 'Clôture humaine'));
        $this->assertSame(InsuranceClaimStatus::Closed, $claim->status);
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(ArchiveDocument::class)->handle($proof)), 'document');
        $this->assertSame(5, $this->inTenant($f, fn () => $claim->statusHistories()->count()));
    }

    public function test_private_documents_rbac_cross_agency_and_blade_workflow_are_enforced(): void
    {
        $f = $this->fixture();
        $policy = $this->activePolicy($f, $this->company($f));
        $document = $this->inTenant($f, fn () => $policy->documents()->firstOrFail());
        $version = $this->inTenant($f, fn () => $document->currentVersion()->firstOrFail());
        Storage::disk(config('documents.disk'))->assertExists($version->stored_path);
        $this->assertSame(Storage::disk(config('documents.disk'))->size($version->stored_path), (int) $version->size_bytes);
        $this->assertSame(hash('sha256', Storage::disk(config('documents.disk'))->get($version->stored_path)), $version->sha256);
        $this->actingAs($f['owner'])->get(route('insurance.policies.show', $policy))->assertOk()->assertSee('Documents privés')->assertSee('Historique append-only');
        $this->get(route('documents.download', $document))->assertOk();
        $this->assertDatabaseHas('document_access_logs', ['document_id' => $document->id, 'action' => 'download']);

        $expected = ['owner' => [200, 403], 'agency-manager' => [200, 403], 'fleet-manager' => [200, 403], 'rental-agent' => [200, 403], 'viewer-auditor' => [200, 403], 'accountant' => [403, 403]];
        foreach ($expected as $role => [$show, $edit]) {
            $user = $role === 'owner' ? $f['owner'] : $f['users'][$role];
            $this->actingAs($user)->get(route('insurance.policies.show', $policy))->assertStatus($show);
            $this->get(route('insurance.policies.edit', $policy))->assertStatus($edit);
        }
        $this->actingAs($f['users']['agency-manager-b'])->get(route('insurance.policies.show', $policy))->assertForbidden();
        $this->get(route('documents.download', $document))->assertForbidden();
        $this->assertFalse(collect(Route::getRoutes())->contains(fn ($route) => str_starts_with($route->uri(), 'storage/')));
    }

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create(['name' => 'Tenant Assurance C2']);
        $agencyA = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create(['name' => 'Agence C2 A']));
        $agencyB = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create(['name' => 'Agence C2 B']));
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => null, 'role_id' => Role::where('slug', 'tenant-owner')->value('id')]);
        $users = [];
        foreach (['agency-manager', 'fleet-manager', 'rental-agent', 'accountant', 'viewer-auditor'] as $role) {
            $users[$role] = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => $agencyA->id, 'role_id' => Role::where('slug', $role)->value('id')]);
        }
        $users['agency-manager-b'] = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => $agencyB->id, 'role_id' => Role::where('slug', 'agency-manager')->value('id')]);
        $f = ['tenant' => $tenant, 'agency_a' => $agencyA, 'agency_b' => $agencyB, 'owner' => $owner, 'users' => $users];
        $category = $this->inTenant($f, fn () => VehicleCategory::create(['code' => 'C2-'.uniqid(), 'name' => 'Assurance C2', 'is_active' => true]));
        $vehicleA = $this->inTenant($f, fn () => app(CreateVehicle::class)->handle(['agency_id' => $agencyA->id, 'vehicle_category_id' => $category->id, 'registration_number' => 'C2-A-'.uniqid(), 'brand' => 'Dacia', 'model' => 'Duster', 'production_year' => 2025, 'fuel_type' => 'diesel', 'transmission' => 'manual', 'current_mileage' => 1000], $owner->id));
        $vehicleB = $this->inTenant($f, fn () => app(CreateVehicle::class)->handle(['agency_id' => $agencyB->id, 'vehicle_category_id' => $category->id, 'registration_number' => 'C2-B-'.uniqid(), 'brand' => 'Renault', 'model' => 'Clio', 'production_year' => 2025, 'fuel_type' => 'petrol', 'transmission' => 'manual', 'current_mileage' => 2000], $owner->id), 'b');

        return [...$f, 'vehicle_a' => $vehicleA, 'vehicle_b' => $vehicleB];
    }

    private function company(array $f): InsuranceCompany
    {
        return $this->inTenant($f, fn () => app(CreateInsuranceCompany::class)->handle(['name' => 'Assureur '.uniqid()]));
    }

    private function draft(array $f, InsuranceCompany $company, string $number = 'C2-POLICY-SECRET-1234', mixed $startsAt = null, mixed $endsAt = null): InsurancePolicy
    {
        return $this->inTenant($f, fn () => app(CreateInsurancePolicy::class)->handle($this->policyData($f, $company, $number, $startsAt, $endsAt), $f['owner']->id));
    }

    private function activePolicy(array $f, InsuranceCompany $company, mixed $startsAt = null, mixed $endsAt = null): InsurancePolicy
    {
        $policy = $this->draft($f, $company, 'C2-ACTIVE-'.uniqid(), $startsAt, $endsAt);
        $this->inTenant($f, function () use ($f, $policy): void {
            app(CreateInsuranceCoverage::class)->handle($policy, $this->coverageData());
            app(AttachDemoInsurancePolicyProof::class)->handle($policy, $f['owner']->id, 'insurance.policy.document.seeded');
        });

        return $this->inTenant($f, fn () => app(ActivateInsurancePolicy::class)->handle($policy, $f['owner']->id));
    }

    private function policyData(array $f, InsuranceCompany $company, string $number = 'C2-POLICY-SECRET-1234', mixed $startsAt = null, mixed $endsAt = null): array
    {
        return ['agency_id' => $f['agency_a']->id, 'vehicle_id' => $f['vehicle_a']->id, 'insurance_company_id' => $company->id, 'policy_number' => $number, 'policy_type' => 'comprehensive', 'starts_at' => $startsAt ?? today()->subMonth(), 'ends_at' => $endsAt ?? today()->addYear(), 'premium_amount' => '1200.50', 'deductible_amount' => '500.25', 'currency' => 'MAD'];
    }

    private function coverageData(string $label = 'Collision'): array
    {
        return ['coverage_type' => 'collision', 'label' => $label, 'limit_amount' => '50000.00', 'deductible_amount' => '500.25', 'terms' => []];
    }

    private function claimData(array $f, InsurancePolicy $policy, mixed $incidentAt): array
    {
        return ['agency_id' => $f['agency_a']->id, 'insurance_policy_id' => $policy->id, 'incident_at' => $incidentAt, 'reported_at' => now(), 'claimed_amount' => '500.00', 'notes' => 'Décision exclusivement humaine.'];
    }

    private function inTenant(array $f, callable $callback, string $agency = 'a'): mixed
    {
        return app(TenantContext::class)->run($f['tenant'], $callback, $f['agency_'.$agency]->id);
    }

    private function expectValidation(callable $callback, string $field): void
    {
        try {
            $callback();
            $this->fail('Une validation métier était attendue pour '.$field.'.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function expectConstraint(callable $callback, string $code): void
    {
        try {
            DB::transaction($callback);
            $this->fail('Une contrainte PostgreSQL était attendue.');
        } catch (QueryException $exception) {
            $this->assertSame($code, $exception->getCode());
        }
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\nDOCUMENT DE DÉMONSTRATION — NON CONTRACTUEL\n%%EOF");
    }
}
