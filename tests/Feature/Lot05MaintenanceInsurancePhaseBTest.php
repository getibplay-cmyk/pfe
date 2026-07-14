<?php

namespace Tests\Feature;

use App\Actions\Maintenance\ApproveMaintenanceOrder;
use App\Actions\Maintenance\CompleteMaintenanceOrder;
use App\Actions\Maintenance\CreateMaintenanceOrder;
use App\Actions\Maintenance\StartMaintenanceOrder;
use App\Actions\Vehicles\CreateVehicle;
use App\Enums\VehicleOperationalStatus;
use App\Models\Agency;
use App\Models\InsuranceClaim;
use App\Models\InsuranceCompany;
use App\Models\InsurancePolicy;
use App\Models\MaintenanceOrder;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VehicleBlock;
use App\Models\VehicleCategory;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Lot05MaintenanceInsurancePhaseBTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_planned_maintenance_does_not_block_but_approval_creates_a_gist_protected_block(): void
    {
        $f = $this->fixture();
        $order = $this->order($f);
        $this->assertSame(0, $this->inTenant($f, fn () => $order->vehicleBlock()->count()));
        $approved = $this->inTenant($f, fn () => app(ApproveMaintenanceOrder::class)->handle($order, $f['user']->id));

        $this->assertSame('approved', $approved->status);
        $this->assertDatabaseHas('vehicle_blocks', ['maintenance_order_id' => $order->id, 'block_type' => 'maintenance', 'status' => 'active']);
        $this->assertNotNull(DB::selectOne("SELECT conname FROM pg_constraint WHERE conname = 'vehicle_blocks_no_active_overlap_excl'"));
    }

    public function test_maintenance_conflicting_with_an_active_reservation_block_is_refused(): void
    {
        $f = $this->fixture();
        $start = CarbonImmutable::now()->addDay()->startOfHour();
        $this->inTenant($f, fn () => VehicleBlock::create(['agency_id' => $f['agency']->id, 'vehicle_id' => $f['vehicle']->id, 'block_type' => 'reservation', 'starts_at' => $start, 'ends_at' => $start->addHours(3), 'status' => 'active', 'reason' => 'Réservation confirmée', 'created_by' => $f['user']->id]));
        $order = $this->order($f, $start->addHour(), $start->addHours(4));

        try {
            $this->inTenant($f, fn () => app(ApproveMaintenanceOrder::class)->handle($order, $f['user']->id));
            $this->fail('Conflit de disponibilité accepté.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('schedule', $exception->errors());
        }
        $this->assertSame('planned', $order->refresh()->status);
    }

    public function test_start_and_completion_update_vehicle_release_block_create_expense_and_due_dates(): void
    {
        $f = $this->fixture();
        $order = $this->order($f);
        $this->inTenant($f, fn () => app(ApproveMaintenanceOrder::class)->handle($order, $f['user']->id));
        $started = $this->inTenant($f, fn () => app(StartMaintenanceOrder::class)->handle($order, $f['user']->id));
        $this->assertSame('in_progress', $started->status);
        $this->assertSame(VehicleOperationalStatus::Maintenance, $f['vehicle']->refresh()->operational_status);

        $completed = $this->inTenant($f, fn () => app(CompleteMaintenanceOrder::class)->handle($order, ['actual_cost' => '1250.50', 'mileage' => 1250, 'next_due_date' => today()->addMonths(6), 'next_due_mileage' => 11250, 'return_to_active' => true], $f['user']->id));
        $this->assertSame('completed', $completed->status);
        $this->assertSame('1250.50', $completed->actual_cost);
        $this->assertFalse(is_float($completed->actual_cost));
        $this->assertSame(11250, $completed->next_due_mileage);
        $this->assertSame('released', $this->inTenant($f, fn () => $completed->vehicleBlock->status->value));
        $this->assertSame(VehicleOperationalStatus::Active, $f['vehicle']->refresh()->operational_status);
        $this->assertDatabaseHas('expenses', ['maintenance_order_id' => $order->id, 'amount' => '1250.50', 'status' => 'draft']);
    }

    public function test_policy_number_is_encrypted_masked_and_policy_expiration_is_detectable(): void
    {
        $f = $this->fixture();
        $policy = $this->policy($f, today()->addDays(10));
        $raw = DB::table('insurance_policies')->where('id', $policy->id)->first();

        $this->assertNotSame('POLICE-SECRET-1234', $raw->policy_number_encrypted);
        $this->assertStringNotContainsString('POLICE-SECRET-1234', json_encode($policy));
        $this->assertStringEndsWith('1234', $policy->maskedPolicyNumber());
        $this->assertSame(1, $this->inTenant($f, fn () => InsurancePolicy::expiring(30)->count()));
        $this->assertNull($policy->document_id);
    }

    public function test_policy_coverages_are_configurable_and_claim_amounts_remain_human_entered(): void
    {
        $f = $this->fixture();
        $policy = $this->policy($f, today()->addYear());
        $coverage = $this->inTenant($f, fn () => $policy->coverages()->create(['coverage_type' => 'collision', 'label' => 'Collision choisie', 'limit_amount' => '50000.00', 'deductible_amount' => '2500.00', 'terms' => ['decision' => 'assureur']]));
        $claim = $this->inTenant($f, fn () => InsuranceClaim::create(['agency_id' => $f['agency']->id, 'insurance_policy_id' => $policy->id, 'claim_number' => 'CLM-2026-000001', 'status' => 'under_review', 'reported_at' => now(), 'claimed_amount' => '7000.00', 'approved_amount' => '4500.00', 'insurer_reference_encrypted' => 'REF-SENSIBLE', 'notes' => 'Décision réservée à l’assureur et aux humains.', 'created_by' => $f['user']->id]));

        $this->assertSame('collision', $coverage->coverage_type);
        $this->assertSame('4500.00', $claim->approved_amount);
        $this->assertFalse(is_float($claim->approved_amount));
        $this->assertStringNotContainsString('REF-SENSIBLE', (string) DB::table('insurance_claims')->where('id', $claim->id)->value('insurer_reference_encrypted'));
    }

    public function test_maintenance_and_insurance_are_cross_tenant_inaccessible(): void
    {
        $a = $this->fixture();
        $order = $this->order($a);
        $policy = $this->policy($a, today()->addYear());
        $b = $this->fixture();

        $this->assertNull($this->inTenant($b, fn () => MaintenanceOrder::find($order->id)));
        $this->assertNull($this->inTenant($b, fn () => InsurancePolicy::find($policy->id)));
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('rentfleet_test', DB::connection()->getDatabaseName());
    }

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create();
        $agency = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create());
        $role = Role::where('slug', 'tenant-owner')->firstOrFail();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => null, 'role_id' => $role->id]);
        $f = compact('tenant', 'agency', 'user');

        return $this->inTenant($f, function () use ($f) {
            $category = VehicleCategory::create(['code' => 'M-'.uniqid(), 'name' => 'Maintenance', 'is_active' => true]);
            $vehicle = app(CreateVehicle::class)->handle(['agency_id' => $f['agency']->id, 'vehicle_category_id' => $category->id, 'registration_number' => 'MT-'.uniqid(), 'brand' => 'Renault', 'model' => 'Clio', 'production_year' => 2025, 'fuel_type' => 'diesel', 'transmission' => 'manual', 'current_mileage' => 1000], $f['user']->id);

            return [...$f, 'vehicle' => $vehicle];
        });
    }

    private function order(array $f, ?CarbonImmutable $start = null, ?CarbonImmutable $end = null): MaintenanceOrder
    {
        $start ??= CarbonImmutable::now()->addDay()->startOfHour();
        $end ??= $start->addHours(2);

        return $this->inTenant($f, fn () => app(CreateMaintenanceOrder::class)->handle(['agency_id' => $f['agency']->id, 'vehicle_id' => $f['vehicle']->id, 'maintenance_type' => 'preventive', 'priority' => 'normal', 'title' => 'Vidange', 'scheduled_start_at' => $start, 'scheduled_end_at' => $end, 'estimated_cost' => '1000.00'], $f['user']->id));
    }

    private function policy(array $f, mixed $endsAt): InsurancePolicy
    {
        return $this->inTenant($f, function () use ($f, $endsAt) {
            $company = InsuranceCompany::create(['name' => 'Assureur '.uniqid(), 'is_active' => true]);
            $policy = new InsurancePolicy(['agency_id' => $f['agency']->id, 'vehicle_id' => $f['vehicle']->id, 'insurance_company_id' => $company->id, 'policy_type' => 'comprehensive', 'starts_at' => today(), 'ends_at' => $endsAt, 'premium_amount' => '5000.00', 'deductible_amount' => '2500.00', 'currency' => 'MAD', 'status' => 'active']);
            $policy->setPolicyNumber('POLICE-SECRET-1234')->save();

            return $policy;
        });
    }

    private function inTenant(array $fixture, callable $callback): mixed
    {
        return app(TenantContext::class)->run($fixture['tenant'], $callback, $fixture['agency']->id);
    }
}
