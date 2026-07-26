<?php

namespace Tests\Feature;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\CreateDriver;
use App\Actions\Documents\StorePrivateDocument;
use App\Actions\Finance\RecordDepositReceipt;
use App\Actions\Rentals\AcceptRentalContract;
use App\Actions\Rentals\ActivateRentalContract;
use App\Actions\Rentals\AttachContractVersionDocument;
use App\Actions\Rentals\CompleteDepartureInspection;
use App\Actions\Rentals\CompleteReturnInspection;
use App\Actions\Rentals\CreateRentalContractFromReservation;
use App\Actions\Rentals\MarkContractReady;
use App\Actions\Rentals\ReportVehicleDamage;
use App\Actions\Reservations\ConfirmReservation;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Vehicles\CreateVehicle;
use App\Enums\CustomerType;
use App\Enums\RentalContractStatus;
use App\Enums\VehicleBlockStatus;
use App\Enums\VerificationStatus;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\ContractCharge;
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

class Lot06GCReturnFinalizationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('documents.disk'));
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_rental_agent_without_charge_review_can_see_and_finalize_an_admissible_return_once(): void
    {
        $fixture = $this->fixture();
        $contract = $fixture['contract'];
        $agent = $this->userFor($fixture, 'rental-agent');

        $this->assertTrue($agent->hasPermission('contract.return'));
        $this->assertFalse($agent->hasPermission('charge.review'));
        $this->actingAs($agent)
            ->get(route('contracts.show', $contract))
            ->assertOk()
            ->assertSee('Finaliser le retour')
            ->assertDontSee('Approuver')
            ->assertDontSee('Rejeter');

        $this->post(route('contracts.returned', $contract), ['reason' => 'Retour admissible sans frais'])
            ->assertRedirect();

        $returned = $this->inTenant($fixture, fn () => $contract->refresh());
        $this->assertSame(RentalContractStatus::Returned, $returned->status);
        $this->assertNotNull($returned->returned_at);
        $this->assertSame($returned->return_mileage, $fixture['vehicle']->refresh()->current_mileage);
        $this->assertSame(VehicleBlockStatus::Released, $this->inTenant($fixture, fn () => $returned->vehicleBlock->refresh()->status));
        $this->assertSame(1, $this->inTenant($fixture, fn () => $returned->statusHistories()->where('to_status', 'returned')->count()));
        $this->assertSame(1, $this->inTenant($fixture, fn () => AuditLog::query()
            ->where('action', 'contract.returned')
            ->where('auditable_type', RentalContract::class)
            ->where('auditable_id', $returned->id)
            ->count()));
        $this->assertSame(0, $this->inTenant($fixture, fn () => $returned->charges()->count()));

        $this->post(route('contracts.returned', $contract))->assertForbidden();
        $this->assertSame(1, $this->inTenant($fixture, fn () => $returned->statusHistories()->where('to_status', 'returned')->count()));
        $this->assertSame(1, $this->inTenant($fixture, fn () => AuditLog::query()
            ->where('action', 'contract.returned')
            ->where('auditable_type', RentalContract::class)
            ->where('auditable_id', $returned->id)
            ->count()));
    }

    public function test_charge_review_without_contract_return_and_user_without_both_cannot_finalize(): void
    {
        $fixture = $this->fixture();
        $contract = $fixture['contract'];

        foreach (['accountant', 'viewer-auditor'] as $role) {
            $user = $this->userFor($fixture, $role);
            $this->assertFalse($user->hasPermission('contract.return'));
            $this->actingAs($user)
                ->get(route('contracts.show', $contract))
                ->assertOk()
                ->assertDontSee('Finaliser le retour');
            $this->post(route('contracts.returned', $contract))->assertForbidden();
            $this->assertSame(RentalContractStatus::ReturnPending, $this->inTenant($fixture, fn () => $contract->refresh()->status));
        }
    }

    public function test_cross_tenant_and_cross_agency_agents_cannot_see_or_finalize_the_return(): void
    {
        $fixture = $this->fixture();
        $contract = $fixture['contract'];

        $foreignTenant = Tenant::factory()->create();
        $foreignAgency = app(TenantContext::class)->run($foreignTenant, fn () => Agency::factory()->create());
        $foreignAgent = User::factory()->create([
            'tenant_id' => $foreignTenant->id,
            'agency_id' => $foreignAgency->id,
            'role_id' => Role::where('slug', 'rental-agent')->value('id'),
        ]);
        $this->actingAs($foreignAgent)->get(route('contracts.show', $contract))->assertNotFound();
        $this->post(route('contracts.returned', $contract))->assertNotFound();

        $otherAgency = $this->inTenant($fixture, fn () => Agency::factory()->create());
        $otherAgencyAgent = User::factory()->create([
            'tenant_id' => $fixture['tenant']->id,
            'agency_id' => $otherAgency->id,
            'role_id' => Role::where('slug', 'rental-agent')->value('id'),
        ]);
        $this->actingAs($otherAgencyAgent)->get(route('contracts.show', $contract))->assertForbidden();
        $this->post(route('contracts.returned', $contract))->assertForbidden();

        $this->assertSame(RentalContractStatus::ReturnPending, $this->inTenant($fixture, fn () => $contract->refresh()->status));
    }

    public function test_wrong_state_and_incomplete_return_inspection_remain_blocked(): void
    {
        $activeFixture = $this->fixture(completeReturn: false);
        $activeContract = $activeFixture['contract'];
        $agent = $this->userFor($activeFixture, 'rental-agent');
        $this->actingAs($agent)
            ->get(route('contracts.show', $activeContract))
            ->assertOk()
            ->assertDontSee('Finaliser le retour');
        $this->post(route('contracts.returned', $activeContract))->assertSessionHasErrors('status');
        $this->assertSame(RentalContractStatus::Active, $this->inTenant($activeFixture, fn () => $activeContract->refresh()->status));

        $incompleteFixture = $this->fixture(completeReturn: false);
        $incompleteContract = $incompleteFixture['contract'];
        $this->inTenant($incompleteFixture, fn () => $incompleteContract->forceFill(['status' => RentalContractStatus::ReturnPending])->save());
        $agent = $this->userFor($incompleteFixture, 'rental-agent');
        $this->actingAs($agent)
            ->get(route('contracts.show', $incompleteContract))
            ->assertOk()
            ->assertDontSee('Finaliser le retour');
        $this->post(route('contracts.returned', $incompleteContract))->assertSessionHasErrors('inspection');
        $this->assertSame(RentalContractStatus::ReturnPending, $this->inTenant($incompleteFixture, fn () => $incompleteContract->refresh()->status));
    }

    public function test_pending_damage_still_blocks_the_return(): void
    {
        $fixture = $this->fixture();
        $contract = $fixture['contract'];
        $inspection = $this->inTenant($fixture, fn () => $contract->inspections()->where('inspection_type', 'return')->firstOrFail());
        $this->inTenant($fixture, fn () => app(ReportVehicleDamage::class)->handle($contract, [
            'return_inspection_id' => $inspection->id,
            'description' => 'Dommage fictif en attente de revue',
            'severity' => 'moderate',
        ], $fixture['owner']->id));

        $agent = $this->userFor($fixture, 'rental-agent');
        $this->actingAs($agent)
            ->get(route('contracts.show', $contract))
            ->assertOk()
            ->assertDontSee('Finaliser le retour')
            ->assertSee('attend la revue humaine des dommages');
        $this->post(route('contracts.returned', $contract))->assertSessionHasErrors('damages');
        $this->assertSame(RentalContractStatus::ReturnPending, $this->inTenant($fixture, fn () => $contract->refresh()->status));
    }

    public function test_charge_decisions_remain_reserved_to_charge_review_permission(): void
    {
        $fixture = $this->fixture();
        $contract = $fixture['contract'];
        $charge = $this->inTenant($fixture, fn () => ContractCharge::create([
            'rental_contract_id' => $contract->id,
            'charge_type' => 'other',
            'description' => 'Frais fictif soumis à décision',
            'quantity' => '1.00',
            'unit_amount' => '25.00',
            'total_amount' => '25.00',
            'status' => 'proposed',
        ]));

        $agent = $this->userFor($fixture, 'rental-agent');
        $this->actingAs($agent)
            ->get(route('contracts.show', $contract))
            ->assertOk()
            ->assertDontSee('Finaliser le retour')
            ->assertSee('attend la décision d’une personne autorisée');
        $this->post(route('contracts.returned', $contract))->assertSessionHasErrors('charges');
        $this->post(route('contracts.returned', $contract), ['approved_charge_ids' => [$charge->id]])->assertForbidden();
        $this->assertSame('proposed', $this->inTenant($fixture, fn () => $charge->refresh()->status->value));
        $this->assertNull($charge->approved_by);

        $manager = $this->userFor($fixture, 'agency-manager');
        $this->actingAs($manager)
            ->get(route('contracts.show', $contract))
            ->assertOk()
            ->assertSee('Finaliser le retour')
            ->assertSee('Approuver')
            ->assertSee('Rejeter');
        $this->post(route('contracts.returned', $contract), ['approved_charge_ids' => [$charge->id]])
            ->assertRedirect();
        $this->assertSame(RentalContractStatus::Returned, $this->inTenant($fixture, fn () => $contract->refresh()->status));
        $this->assertSame('approved', $this->inTenant($fixture, fn () => $charge->refresh()->status->value));
        $this->assertSame($manager->id, $charge->approved_by);
    }

    private function fixture(bool $completeReturn = true): array
    {
        $tenant = Tenant::factory()->create();
        $agency = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create());
        $owner = User::factory()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => null,
            'role_id' => Role::where('slug', 'tenant-owner')->value('id'),
        ]);
        $fixture = compact('tenant', 'agency', 'owner');

        $fixture = $this->inTenant($fixture, function () use ($fixture): array {
            $category = VehicleCategory::create(['code' => 'G-'.uniqid(), 'name' => 'Catégorie retour 06G-C', 'is_active' => true]);
            $vehicle = app(CreateVehicle::class)->handle([
                'agency_id' => $fixture['agency']->id,
                'vehicle_category_id' => $category->id,
                'registration_number' => 'G6C-'.uniqid(),
                'brand' => 'Dacia',
                'model' => 'Duster',
                'production_year' => 2025,
                'fuel_type' => 'diesel',
                'transmission' => 'manual',
                'current_mileage' => 1000,
            ], $fixture['owner']->id);
            $customer = app(CreateCustomer::class)->handle([
                'agency_id' => $fixture['agency']->id,
                'customer_type' => CustomerType::Individual,
                'first_name' => 'Client',
                'last_name' => 'Fictif 06G-C',
                'verification_status' => VerificationStatus::Verified,
            ]);
            $driver = app(CreateDriver::class)->handle($customer, [
                'first_name' => 'Conducteur',
                'last_name' => 'Fictif 06G-C',
                'licence_number' => 'PERMIS-'.uniqid(),
                'licence_expires_at' => today()->addYears(2),
                'verification_status' => VerificationStatus::Verified,
                'is_primary' => true,
            ]);
            PricingRule::create([
                'agency_id' => null,
                'vehicle_category_id' => $category->id,
                'name' => 'Tarif retour 06G-C',
                'daily_rate' => '400.00',
                'deposit_amount' => '300.00',
                'included_km_per_day' => 200,
                'extra_km_rate' => '2.50',
                'late_hour_rate' => '75.00',
                'minimum_days' => 1,
                'maximum_days' => 30,
                'valid_from' => today()->subYear(),
                'priority' => 0,
                'currency' => 'MAD',
                'conditions' => [],
                'is_active' => true,
                'created_by' => $fixture['owner']->id,
            ]);
            $start = CarbonImmutable::now()->addDays(3)->startOfHour();
            $reservation = app(CreateReservation::class)->handle([
                'agency_id' => $fixture['agency']->id,
                'customer_id' => $customer->id,
                'driver_id' => $driver->id,
                'vehicle_category_id' => $category->id,
                'vehicle_id' => $vehicle->id,
                'starts_at' => $start,
                'ends_at' => $start->addDay(),
                'status' => 'draft',
            ], $fixture['owner']->id);
            app(ConfirmReservation::class)->handle($reservation, $fixture['owner']->id);

            return [...$fixture, 'category' => $category, 'vehicle' => $vehicle, 'customer' => $customer, 'driver' => $driver, 'reservation' => $reservation];
        });

        $this->inTenant($fixture, function () use ($fixture): void {
            $pdf = fn (string $name) => UploadedFile::fake()->createWithContent($name, "%PDF-1.4\nDocument fictif 06G-C\n%%EOF");
            app(StorePrivateDocument::class)->handle($fixture['customer'], ['document_type' => 'customer_identity', 'title' => 'Identité fictive', 'is_sensitive' => true], $pdf('identite.pdf'), $fixture['owner']->id);
            app(StorePrivateDocument::class)->handle($fixture['driver'], ['document_type' => 'driving_licence', 'title' => 'Permis fictif', 'is_sensitive' => true], $pdf('permis.pdf'), $fixture['owner']->id);
        });

        $contract = $this->inTenant($fixture, fn () => app(CreateRentalContractFromReservation::class)->handle($fixture['reservation'], $fixture['owner']->id));
        $this->inTenant($fixture, fn () => app(AttachContractVersionDocument::class)->handle(
            $contract,
            UploadedFile::fake()->createWithContent('contrat.pdf', "%PDF-1.4\nContrat fictif 06G-C\n%%EOF"),
            $fixture['owner']->id,
        ));
        $this->inTenant($fixture, fn () => app(MarkContractReady::class)->handle($contract, $fixture['owner']->id));
        $this->inTenant($fixture, fn () => app(AcceptRentalContract::class)->handle($contract, [
            'accepted_by_name' => 'Signataire fictif',
            'acceptance_method' => 'typed_name',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit 06G-C',
        ], $fixture['owner']->id));
        $this->inTenant($fixture, fn () => app(CompleteDepartureInspection::class)->handle($contract, [
            'mileage' => 1010,
            'fuel_level' => '75.00',
            'items' => $this->inspectionItems(),
        ], $fixture['owner']->id));
        $this->inTenant($fixture, fn () => app(RecordDepositReceipt::class)->handle(
            $contract,
            $contract->deposit_required,
            '06gc-deposit-'.$contract->id,
            $fixture['owner']->id,
        ));
        $this->inTenant($fixture, fn () => app(ActivateRentalContract::class)->handle($contract, $fixture['owner']->id));

        if ($completeReturn) {
            $this->inTenant($fixture, fn () => app(CompleteReturnInspection::class)->handle($contract, [
                'mileage' => 1110,
                'fuel_level' => '75.00',
                'items' => $this->inspectionItems(),
            ], $fixture['owner']->id));
        }

        return [...$fixture, 'contract' => $contract->refresh()];
    }

    private function userFor(array $fixture, string $role): User
    {
        return User::factory()->create([
            'tenant_id' => $fixture['tenant']->id,
            'agency_id' => $fixture['agency']->id,
            'role_id' => Role::where('slug', $role)->value('id'),
        ]);
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
