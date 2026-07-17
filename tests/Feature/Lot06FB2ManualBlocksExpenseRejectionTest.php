<?php

namespace Tests\Feature;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\CreateDriver;
use App\Actions\Finance\ApproveExpense;
use App\Actions\Finance\CreateExpense;
use App\Actions\Finance\RejectExpense;
use App\Actions\Maintenance\ApproveMaintenanceOrder;
use App\Actions\Maintenance\CreateMaintenanceOrder;
use App\Actions\Rentals\CreateRentalContractFromReservation;
use App\Actions\Reservations\ConfirmReservation;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\SearchAvailableVehicles;
use App\Actions\VehicleBlocks\CreateManualVehicleBlock;
use App\Actions\VehicleBlocks\ReleaseManualVehicleBlock;
use App\Actions\Vehicles\CreateVehicle;
use App\Enums\CustomerType;
use App\Enums\VerificationStatus;
use App\Models\Agency;
use App\Models\Expense;
use App\Models\PricingRule;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleBlock;
use App\Models\VehicleCategory;
use App\Support\Tenancy\TenantContext;
use App\Support\Ui\NavigationBuilder;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Lot06FB2ManualBlocksExpenseRejectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_manual_block_creation_forces_server_fields_and_is_audited(): void
    {
        $f = $this->fixture();
        $start = now()->addDay()->startOfHour()->toImmutable();
        $block = $this->inTenant($f, fn () => app(CreateManualVehicleBlock::class)->handle($this->manualData($f, $start), $f['owner']->id));

        $this->assertSame($f['tenant']->id, $block->tenant_id);
        $this->assertSame('manual', $block->block_type->value);
        $this->assertSame('active', $block->status->value);
        $this->assertNull($block->reservation_id);
        $this->assertNull($block->rental_contract_id);
        $this->assertNull($block->maintenance_order_id);
        $this->assertSame($f['owner']->id, $block->created_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'vehicle_block.manual.created', 'auditable_id' => $block->id]);
    }

    public function test_forged_fields_invalid_period_and_missing_reason_are_refused_by_http(): void
    {
        $f = $this->fixture();
        $start = now()->addDay()->startOfHour();

        $this->actingAs($f['owner'])->post(route('vehicle-blocks.store'), [
            ...$this->manualData($f, $start->toImmutable()),
            'tenant_id' => 999,
            'block_type' => 'reservation',
            'status' => 'released',
            'created_by' => 999,
        ])->assertSessionHasErrors(['tenant_id', 'block_type', 'status', 'created_by']);

        $this->actingAs($f['owner'])->post(route('vehicle-blocks.store'), [
            ...$this->manualData($f, $start->toImmutable()),
            'ends_at' => $start->subMinute()->toIso8601String(),
        ])->assertSessionHasErrors('ends_at');

        $this->actingAs($f['owner'])->post(route('vehicle-blocks.store'), [
            ...$this->manualData($f, $start->toImmutable()),
            'reason' => '',
        ])->assertSessionHasErrors('reason');

        $this->assertSame(0, VehicleBlock::withoutGlobalScopes()->count());
    }

    public function test_cross_agency_vehicle_and_user_without_manage_permission_are_refused(): void
    {
        $f = $this->fixture();
        $fleetManager = $this->userForRole($f, 'fleet-manager', $f['agency']);
        $rentalAgent = $this->userForRole($f, 'rental-agent', $f['agency']);
        $start = now()->addDay()->startOfHour()->toImmutable();

        $this->actingAs($fleetManager)->post(route('vehicle-blocks.store'), [
            ...$this->manualData($f, $start),
            'agency_id' => $f['otherAgency']->id,
            'vehicle_id' => $f['otherVehicle']->id,
        ])->assertForbidden();

        $this->actingAs($rentalAgent)->get(route('vehicle-blocks.create'))->assertForbidden();
        $this->actingAs($rentalAgent)->post(route('vehicle-blocks.store'), $this->manualData($f, $start))->assertForbidden();
        $this->assertSame(0, VehicleBlock::withoutGlobalScopes()->count());
    }

    public function test_reservation_contract_and_maintenance_overlaps_become_french_business_errors(): void
    {
        $f = $this->fixture();
        $base = now()->addDays(3)->startOfHour()->toImmutable();

        $reservationBlock = $this->reservationBlock($f, $f['vehicle'], $base, $base->addHours(2));
        $this->assertOverlapRejected($f, $f['vehicle'], $base->addHour(), $base->addHours(3));

        $contractVehicle = $this->vehicle($f, 'CONTRACT-'.uniqid());
        $contractBlock = $this->reservationBlock($f, $contractVehicle, $base->addDay(), $base->addDay()->addHours(2), true);
        $this->assertOverlapRejected($f, $contractVehicle, $base->addDay()->addHour(), $base->addDay()->addHours(3));

        $maintenanceVehicle = $this->vehicle($f, 'MAINT-'.uniqid());
        $maintenanceBlock = $this->maintenanceBlock($f, $maintenanceVehicle, $base->addDays(2), $base->addDays(2)->addHours(2));
        $this->assertOverlapRejected($f, $maintenanceVehicle, $base->addDays(2)->addHour(), $base->addDays(2)->addHours(3));

        $this->assertSame('reservation', $reservationBlock->block_type->value);
        $this->assertSame('contract', $contractBlock->block_type->value);
        $this->assertSame('maintenance', $maintenanceBlock->block_type->value);
    }

    public function test_adjacent_manual_slots_are_allowed_and_direct_incoherent_source_is_rejected(): void
    {
        $f = $this->fixture();
        $start = now()->addDays(4)->startOfHour()->toImmutable();
        $first = $this->inTenant($f, fn () => app(CreateManualVehicleBlock::class)->handle($this->manualData($f, $start, $start->addHours(2)), $f['owner']->id));
        $second = $this->inTenant($f, fn () => app(CreateManualVehicleBlock::class)->handle($this->manualData($f, $start->addHours(2), $start->addHours(4)), $f['owner']->id));

        $this->assertNotSame($first->id, $second->id);
        $reservationBlock = $this->reservationBlock($f, $this->vehicle($f, 'SOURCE-'.uniqid()), $start->addDay(), $start->addDay()->addHours(2));

        try {
            DB::table('vehicle_blocks')->insert([
                'tenant_id' => $f['tenant']->id,
                'agency_id' => $f['agency']->id,
                'vehicle_id' => $reservationBlock->vehicle_id,
                'reservation_id' => $reservationBlock->reservation_id,
                'block_type' => 'manual',
                'starts_at' => $start->addDays(2),
                'ends_at' => $start->addDays(2)->addHour(),
                'status' => 'active',
                'reason' => 'Source forgée',
                'created_by' => $f['owner']->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Un bloc manuel lié à une réservation a été accepté.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
    }

    public function test_manual_block_controls_availability_and_release_allows_confirmation(): void
    {
        $f = $this->fixture();
        $start = now()->addDays(5)->startOfHour()->toImmutable();
        $block = $this->inTenant($f, fn () => app(CreateManualVehicleBlock::class)->handle($this->manualData($f, $start, $start->addHours(2)), $f['owner']->id));

        $unavailable = $this->inTenant($f, fn () => app(SearchAvailableVehicles::class)->query($f['agency']->id, $start, $start->addHour())->pluck('id'));
        $this->assertNotContains($f['vehicle']->id, $unavailable);

        $released = $this->inTenant($f, fn () => app(ReleaseManualVehicleBlock::class)->handle($block));
        $available = $this->inTenant($f, fn () => app(SearchAvailableVehicles::class)->query($f['agency']->id, $start, $start->addHour())->pluck('id'));
        $this->assertSame('released', $released->status->value);
        $this->assertContains($f['vehicle']->id, $available);

        $reservationBlock = $this->reservationBlock($f, $f['vehicle'], $start, $start->addHours(2));
        $this->assertSame('active', $reservationBlock->status->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'vehicle_block.manual.released', 'auditable_id' => $block->id]);
    }

    public function test_non_manual_block_cannot_be_released_by_manual_module(): void
    {
        $f = $this->fixture();
        $start = now()->addDays(3)->startOfHour()->toImmutable();
        $block = $this->reservationBlock($f, $f['vehicle'], $start, $start->addHours(2));

        $this->actingAs($f['owner'])->post(route('vehicle-blocks.release', $block))->assertForbidden();
        $this->assertSame('active', $block->refresh()->status->value);
    }

    public function test_draft_expense_can_be_rejected_once_without_amount_mutation_and_with_audit(): void
    {
        $f = $this->fixture();
        $accountant = $this->userForRole($f, 'accountant', $f['agency']);
        $expense = $this->expense($f, $f['agency'], $f['vehicle'], $accountant, '125.50');

        $response = $this->actingAs($accountant)->post(route('finance.expenses.reject', $expense), ['reason' => 'Justificatif non conforme']);
        $response->assertRedirect()->assertSessionHasNoErrors();
        $rejected = $expense->refresh();

        $this->assertSame('rejected', $rejected->status);
        $this->assertSame('125.50', $rejected->amount);
        $this->assertSame($accountant->id, $rejected->rejected_by);
        $this->assertNotNull($rejected->rejected_at);
        $this->assertSame('Justificatif non conforme', $rejected->rejection_reason);
        $this->assertDatabaseHas('audit_logs', ['action' => 'expense.rejected', 'auditable_id' => $expense->id]);

        $this->expectValidation(
            fn () => $this->inTenant($f, fn () => app(RejectExpense::class)->handle($rejected, 'Autre motif', $accountant->id)),
            'expense'
        );
    }

    public function test_expense_rejection_state_machine_and_cross_agency_scope_are_enforced(): void
    {
        $f = $this->fixture();
        $accountant = $this->userForRole($f, 'accountant', $f['agency']);
        $draft = $this->expense($f, $f['agency'], $f['vehicle'], $accountant);
        $this->actingAs($accountant)->post(route('finance.expenses.reject', $draft), ['reason' => ''])->assertSessionHasErrors('reason');

        $approved = $this->expense($f, $f['agency'], $f['vehicle'], $accountant);
        $this->inTenant($f, fn () => app(ApproveExpense::class)->handle($approved, $accountant->id));
        $this->actingAs($accountant)->post(route('finance.expenses.reject', $approved), ['reason' => 'Trop tard'])->assertSessionHasErrors('expense');

        $rejected = $this->expense($f, $f['agency'], $f['vehicle'], $accountant);
        $this->inTenant($f, fn () => app(RejectExpense::class)->handle($rejected, 'Dépense refusée', $accountant->id));
        $this->actingAs($accountant)->post(route('finance.expenses.approve', $rejected))->assertSessionHasErrors('expense');

        $foreign = $this->expense($f, $f['otherAgency'], $f['otherVehicle'], $f['owner']);
        $this->actingAs($accountant)->post(route('finance.expenses.reject', $foreign), ['reason' => 'Cross agence'])->assertForbidden();
    }

    public function test_postgresql_prevents_terminal_expense_mutation_and_deletion(): void
    {
        $f = $this->fixture();
        $expense = $this->expense($f, $f['agency'], $f['vehicle'], $f['owner']);
        $this->inTenant($f, fn () => app(RejectExpense::class)->handle($expense, 'Décision terminale', $f['owner']->id));

        try {
            DB::table('expenses')->where('id', $expense->id)->update(['amount' => '999.00']);
            $this->fail('Une dépense terminale a été modifiée.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
    }

    public function test_postgresql_prevents_terminal_expense_deletion(): void
    {
        $f = $this->fixture();
        $expense = $this->expense($f, $f['agency'], $f['vehicle'], $f['owner']);
        $this->inTenant($f, fn () => app(RejectExpense::class)->handle($expense, 'Décision terminale', $f['owner']->id));

        try {
            DB::table('expenses')->where('id', $expense->id)->delete();
            $this->fail('Une dépense terminale a été supprimée.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
    }

    public function test_expense_rbac_matrix_is_exact_for_six_roles(): void
    {
        $expensePermissions = ['expense.view', 'expense.create', 'expense.approve', 'expense.reject'];
        $expected = [
            'tenant-owner' => $expensePermissions,
            'accountant' => $expensePermissions,
            'agency-manager' => ['expense.view'],
            'fleet-manager' => ['expense.view'],
            'rental-agent' => [],
            'viewer-auditor' => ['expense.view'],
        ];

        foreach ($expected as $slug => $permissions) {
            $actual = Role::where('slug', $slug)->firstOrFail()->permissions()
                ->whereIn('slug', $expensePermissions)->pluck('slug')->sort()->values()->all();
            sort($permissions);
            $this->assertSame($permissions, $actual, $slug);
        }
    }

    public function test_expense_only_user_sees_only_expenses_in_finance_and_navigation_is_coherent(): void
    {
        $f = $this->fixture();
        $fleetManager = $this->userForRole($f, 'fleet-manager', $f['agency']);
        $expense = $this->expense($f, $f['agency'], $f['vehicle'], $f['owner']);

        $response = $this->actingAs($fleetManager)->get(route('finance.index'));
        $response->assertOk()
            ->assertSee($expense->expense_number)
            ->assertDontSee('Factures')
            ->assertDontSee('Paiements récents')
            ->assertDontSee('Cautions')
            ->assertDontSee('Créer une dépense')
            ->assertDontSee('Approuver')
            ->assertDontSee('Rejeter')
            ->assertViewHas('invoices', fn ($value) => $value === null)
            ->assertViewHas('payments', fn ($value) => $value === null)
            ->assertViewHas('deposits', fn ($value) => $value === null)
            ->assertViewHas('expenses', fn ($value) => $value->total() === 1);

        $keys = collect(app(NavigationBuilder::class)->for($fleetManager))->pluck('items')->flatten(1)->pluck('key');
        $this->assertContains('finance', $keys);
        $this->assertContains('vehicle-blocks', $keys);

        $emptyRole = Role::create(['tenant_id' => $f['tenant']->id, 'slug' => 'no-finance-'.uniqid(), 'name' => 'Sans finance', 'is_system' => false]);
        $withoutFinance = User::factory()->create(['tenant_id' => $f['tenant']->id, 'agency_id' => $f['agency']->id, 'role_id' => $emptyRole->id]);
        $this->actingAs($withoutFinance)->get(route('finance.index'))->assertForbidden();
    }

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create();
        $role = Role::where('slug', 'tenant-owner')->firstOrFail();
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => null, 'role_id' => $role->id]);
        $base = compact('tenant', 'owner');

        return app(TenantContext::class)->run($tenant, function () use ($base) {
            $agency = Agency::factory()->create();
            $otherAgency = Agency::factory()->create();
            $category = VehicleCategory::create(['code' => 'B2-'.uniqid(), 'name' => 'Catégorie B2', 'is_active' => true]);
            $fixture = [...$base, 'agency' => $agency, 'otherAgency' => $otherAgency, 'category' => $category];
            $vehicle = $this->vehicle($fixture, 'B2-'.uniqid(), $agency);
            $otherVehicle = $this->vehicle($fixture, 'B2-OTHER-'.uniqid(), $otherAgency);

            return [...$fixture, 'vehicle' => $vehicle, 'otherVehicle' => $otherVehicle];
        });
    }

    private function vehicle(array $f, string $registration, ?Agency $agency = null): Vehicle
    {
        return app(TenantContext::class)->run($f['tenant'], fn () => app(CreateVehicle::class)->handle([
            'agency_id' => ($agency ?? $f['agency'])->id,
            'vehicle_category_id' => $f['category']->id,
            'registration_number' => $registration,
            'brand' => 'Dacia',
            'model' => 'Logan',
            'production_year' => 2025,
            'fuel_type' => 'diesel',
            'transmission' => 'manual',
            'current_mileage' => 1000,
        ], $f['owner']->id));
    }

    private function manualData(array $f, CarbonImmutable $start, ?CarbonImmutable $end = null): array
    {
        return [
            'agency_id' => $f['agency']->id,
            'vehicle_id' => $f['vehicle']->id,
            'starts_at' => $start->toIso8601String(),
            'ends_at' => ($end ?? $start->addHours(2))->toIso8601String(),
            'reason' => 'Immobilisation opérationnelle planifiée',
        ];
    }

    private function reservationBlock(array $f, Vehicle $vehicle, CarbonImmutable $start, CarbonImmutable $end, bool $convert = false): VehicleBlock
    {
        return $this->inTenant($f, function () use ($f, $vehicle, $start, $end, $convert) {
            $customer = app(CreateCustomer::class)->handle(['agency_id' => $f['agency']->id, 'customer_type' => CustomerType::Individual, 'first_name' => 'Client', 'last_name' => uniqid(), 'verification_status' => VerificationStatus::Verified]);
            $driver = app(CreateDriver::class)->handle($customer, ['first_name' => 'Conducteur', 'last_name' => uniqid(), 'licence_number' => 'B2-'.uniqid(), 'licence_expires_at' => today()->addYears(2), 'verification_status' => VerificationStatus::Verified, 'is_primary' => true]);
            PricingRule::create(['agency_id' => null, 'vehicle_category_id' => $f['category']->id, 'name' => 'Tarif '.uniqid(), 'daily_rate' => '400.00', 'deposit_amount' => '1000.00', 'minimum_days' => 1, 'maximum_days' => 30, 'valid_from' => today()->subYear(), 'priority' => 0, 'currency' => 'MAD', 'conditions' => [], 'is_active' => true, 'created_by' => $f['owner']->id]);
            $reservation = app(CreateReservation::class)->handle(['agency_id' => $f['agency']->id, 'customer_id' => $customer->id, 'driver_id' => $driver->id, 'vehicle_category_id' => $f['category']->id, 'vehicle_id' => $vehicle->id, 'starts_at' => $start, 'ends_at' => $end, 'status' => 'draft'], $f['owner']->id);
            app(ConfirmReservation::class)->handle($reservation, $f['owner']->id);

            if ($convert) {
                $contract = app(CreateRentalContractFromReservation::class)->handle($reservation->refresh(), $f['owner']->id);

                return $contract->vehicleBlock->refresh();
            }

            return $reservation->activeVehicleBlock->refresh();
        });
    }

    private function maintenanceBlock(array $f, Vehicle $vehicle, CarbonImmutable $start, CarbonImmutable $end): VehicleBlock
    {
        return $this->inTenant($f, function () use ($f, $vehicle, $start, $end) {
            $order = app(CreateMaintenanceOrder::class)->handle(['agency_id' => $f['agency']->id, 'vehicle_id' => $vehicle->id, 'maintenance_type' => 'preventive', 'priority' => 'normal', 'title' => 'Maintenance B2', 'scheduled_start_at' => $start, 'scheduled_end_at' => $end], $f['owner']->id);
            app(ApproveMaintenanceOrder::class)->handle($order, $f['owner']->id);

            return $order->vehicleBlock->refresh();
        });
    }

    private function expense(array $f, Agency $agency, Vehicle $vehicle, User $actor, string $amount = '100.00'): Expense
    {
        return app(TenantContext::class)->run($f['tenant'], fn () => app(CreateExpense::class)->handle([
            'agency_id' => $agency->id,
            'vehicle_id' => $vehicle->id,
            'category' => 'administration',
            'description' => 'Dépense de validation B2',
            'amount' => $amount,
            'tax_amount' => '0.00',
            'currency' => 'MAD',
            'expense_date' => today(),
        ], $actor->id));
    }

    private function userForRole(array $f, string $slug, Agency $agency): User
    {
        return User::factory()->create([
            'tenant_id' => $f['tenant']->id,
            'agency_id' => $agency->id,
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
        ]);
    }

    private function assertOverlapRejected(array $f, Vehicle $vehicle, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $response = $this->actingAs($f['owner'])->post(route('vehicle-blocks.store'), [
            ...$this->manualData($f, $start, $end),
            'vehicle_id' => $vehicle->id,
        ]);
        $response->assertSessionHasErrors('vehicle_id');
        $message = $response->getSession()->get('errors')->first('vehicle_id');
        $this->assertStringContainsString('déjà bloqué', $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);
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
