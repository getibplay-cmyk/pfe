<?php

namespace Tests\Feature;

use App\Actions\Maintenance\ApproveMaintenanceOrder;
use App\Actions\Maintenance\CancelMaintenanceOrder;
use App\Actions\Maintenance\CompleteMaintenanceOrder;
use App\Actions\Maintenance\CreateMaintenanceOrder;
use App\Actions\Maintenance\StartMaintenanceOrder;
use App\Actions\Vehicles\CreateVehicle;
use App\Enums\DocumentType;
use App\Enums\VehicleOperationalStatus;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\MaintenanceOrder;
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
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Lot06FC1MaintenanceCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('documents.disk'));
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_planned_order_can_be_fully_edited_but_approved_terminal_and_cross_agency_edits_are_rejected(): void
    {
        $f = $this->fixture();
        $order = $this->order($f);
        $this->assertDatabaseHas('audit_logs', ['action' => 'maintenance.created', 'auditable_id' => $order->id]);
        $start = CarbonImmutable::now()->addDays(5)->startOfHour();
        $payload = $this->editData($f, $start, $start->addHours(3));

        $this->actingAs($f['owner'])->get(route('maintenance.edit', $order))->assertOk()->assertSee('Modifier la maintenance planifiée');
        $this->put(route('maintenance.update', $order), $payload)->assertRedirect(route('maintenance.show', $order));
        $updated = $this->inTenant($f, fn () => $order->refresh());
        $this->assertSame('Révision complète', $updated->title);
        $this->assertSame('1450.75', $updated->estimated_cost);
        $this->assertFalse(is_float($updated->estimated_cost));
        $this->assertDatabaseHas('audit_logs', ['action' => 'maintenance.updated', 'auditable_id' => $order->id]);

        $this->inTenant($f, fn () => app(ApproveMaintenanceOrder::class)->handle($order, $f['owner']->id));
        $this->put(route('maintenance.update', $order), $payload)->assertForbidden();

        $cancelled = $this->order($f, CarbonImmutable::now()->addDays(8)->startOfHour());
        $this->inTenant($f, fn () => app(CancelMaintenanceOrder::class)->handle($cancelled, 'Plan annulé', $f['owner']->id));
        $this->get(route('maintenance.edit', $cancelled))->assertForbidden();

        $crossAgency = $this->editData($f, CarbonImmutable::now()->addDays(10)->startOfHour(), CarbonImmutable::now()->addDays(10)->startOfHour()->addHours(2));
        $crossAgency['vehicle_id'] = $f['vehicle_b']->id;
        $planned = $this->order($f, CarbonImmutable::now()->addDays(9)->startOfHour());
        $this->put(route('maintenance.update', $planned), $crossAgency)->assertSessionHasErrors('vehicle_id');
        $this->assertSame($f['vehicle_a']->id, $this->inTenant($f, fn () => $planned->refresh()->vehicle_id));
    }

    public function test_approved_reschedule_updates_order_and_block_atomically_and_translates_gist_conflicts(): void
    {
        $f = $this->fixture();
        $order = $this->order($f);
        $this->inTenant($f, fn () => app(ApproveMaintenanceOrder::class)->handle($order, $f['owner']->id));
        $newStart = CarbonImmutable::now()->addDays(6)->startOfHour();

        $this->actingAs($f['owner'])->patch(route('maintenance.reschedule', $order), [
            'scheduled_start_at' => $newStart->toIso8601String(),
            'scheduled_end_at' => $newStart->addHours(2)->toIso8601String(),
            'reason' => 'Disponibilité du prestataire',
        ])->assertRedirect(route('maintenance.show', $order));

        $order->refresh();
        $block = $this->inTenant($f, fn () => $order->vehicleBlock()->firstOrFail());
        $this->assertTrue($order->scheduled_start_at->equalTo($block->starts_at));
        $this->assertTrue($order->scheduled_end_at->equalTo($block->ends_at));
        $this->assertDatabaseHas('maintenance_status_histories', ['maintenance_order_id' => $order->id, 'from_status' => 'approved', 'to_status' => 'approved', 'reason' => 'Disponibilité du prestataire']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'maintenance.rescheduled', 'auditable_id' => $order->id]);

        $conflictStart = CarbonImmutable::now()->addDays(12)->startOfHour();
        $this->inTenant($f, fn () => VehicleBlock::create([
            'agency_id' => $f['agency_a']->id,
            'vehicle_id' => $f['vehicle_a']->id,
            'block_type' => 'manual',
            'starts_at' => $conflictStart,
            'ends_at' => $conflictStart->addHours(4),
            'status' => 'active',
            'reason' => 'Immobilisation contrôlée',
            'created_by' => $f['owner']->id,
        ]));
        $beforeStart = $order->scheduled_start_at;
        $beforeEnd = $order->scheduled_end_at;

        $response = $this->patch(route('maintenance.reschedule', $order), [
            'scheduled_start_at' => $conflictStart->addHour()->toIso8601String(),
            'scheduled_end_at' => $conflictStart->addHours(3)->toIso8601String(),
            'reason' => 'Conflit attendu',
        ]);
        $response->assertSessionHasErrors('schedule');
        $this->assertStringNotContainsString('SQLSTATE', session('errors')->first('schedule'));
        $this->assertTrue($beforeStart->equalTo($this->inTenant($f, fn () => $order->refresh()->scheduled_start_at)));
        $this->assertTrue($beforeEnd->equalTo($this->inTenant($f, fn () => $order->vehicleBlock()->firstOrFail()->ends_at)));
    }

    public function test_cycle_requires_one_coherent_block_updates_vehicle_and_creates_one_expense(): void
    {
        $f = $this->fixture();
        $order = $this->order($f);
        $this->inTenant($f, fn () => app(ApproveMaintenanceOrder::class)->handle($order, $f['owner']->id));
        $this->assertSame(1, $this->inTenant($f, fn () => $order->vehicleBlock()->count()));
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(ApproveMaintenanceOrder::class)->handle($order, $f['owner']->id)), 'maintenance');
        $this->assertSame(1, $this->inTenant($f, fn () => $order->vehicleBlock()->count()));

        $started = $this->inTenant($f, fn () => app(StartMaintenanceOrder::class)->handle($order, $f['owner']->id));
        $this->assertSame('in_progress', $started->status);
        $this->assertSame(VehicleOperationalStatus::Maintenance, $this->inTenant($f, fn () => $f['vehicle_a']->refresh()->operational_status));

        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(CompleteMaintenanceOrder::class)->handle($order, ['actual_cost' => '875.25', 'mileage' => 999, 'return_to_active' => true], $f['owner']->id)), 'mileage');
        $completed = $this->inTenant($f, fn () => app(CompleteMaintenanceOrder::class)->handle($order, ['actual_cost' => '875.25', 'mileage' => 1200, 'next_due_date' => today()->addMonths(6), 'next_due_mileage' => 11200, 'return_to_active' => true, 'reason' => 'Travaux validés'], $f['owner']->id));
        $this->assertSame('completed', $completed->status);
        $this->assertSame(1200, $this->inTenant($f, fn () => $f['vehicle_a']->refresh()->current_mileage));
        $this->assertSame('released', $this->inTenant($f, fn () => $completed->vehicleBlock->status->value));
        $this->assertSame(VehicleOperationalStatus::Active, $this->inTenant($f, fn () => $f['vehicle_a']->refresh()->operational_status));
        $this->assertSame(1, $this->inTenant($f, fn () => $completed->expenses()->count()));
        foreach (['maintenance.approved', 'maintenance.started', 'maintenance.completed'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'auditable_id' => $order->id]);
        }
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(CompleteMaintenanceOrder::class)->handle($order, ['actual_cost' => '875.25', 'mileage' => 1200, 'return_to_active' => true], $f['owner']->id)), 'maintenance');
        $this->assertSame(1, $this->inTenant($f, fn () => $completed->expenses()->count()));
    }

    public function test_start_rejects_incoherent_block_and_cancel_releases_only_its_own_block(): void
    {
        $f = $this->fixture();
        $order = $this->order($f);
        $this->inTenant($f, fn () => app(ApproveMaintenanceOrder::class)->handle($order, $f['owner']->id));
        $this->inTenant($f, fn () => $order->vehicleBlock()->firstOrFail()->forceFill(['status' => 'released', 'released_at' => now()])->save());
        $this->expectValidation(fn () => $this->inTenant($f, fn () => app(StartMaintenanceOrder::class)->handle($order, $f['owner']->id)), 'maintenance');
        $this->assertSame('approved', $this->inTenant($f, fn () => $order->refresh()->status));

        $cancelled = $this->order($f, CarbonImmutable::now()->addDays(15)->startOfHour());
        $this->inTenant($f, fn () => app(ApproveMaintenanceOrder::class)->handle($cancelled, $f['owner']->id));
        $manual = $this->inTenant($f, fn () => VehicleBlock::create([
            'agency_id' => $f['agency_a']->id, 'vehicle_id' => $f['vehicle_a']->id, 'block_type' => 'manual',
            'starts_at' => CarbonImmutable::now()->addDays(20), 'ends_at' => CarbonImmutable::now()->addDays(20)->addHour(),
            'status' => 'active', 'reason' => 'Bloc distinct', 'created_by' => $f['owner']->id,
        ]));
        $this->inTenant($f, fn () => app(CancelMaintenanceOrder::class)->handle($cancelled, 'Intervention retirée', $f['owner']->id));
        $this->assertSame('cancelled', $this->inTenant($f, fn () => $cancelled->vehicleBlock()->firstOrFail()->status->value));
        $this->assertSame('active', $this->inTenant($f, fn () => $manual->refresh()->status->value));
        $this->assertDatabaseHas('audit_logs', ['action' => 'maintenance.cancelled', 'auditable_id' => $cancelled->id]);
    }

    public function test_postgresql_rejects_terminal_in_progress_and_history_direct_mutations(): void
    {
        $f = $this->fixture();
        $order = $this->order($f);
        $this->inTenant($f, function () use ($f, $order) {
            app(ApproveMaintenanceOrder::class)->handle($order, $f['owner']->id);
            app(StartMaintenanceOrder::class)->handle($order, $f['owner']->id);
        });
        $this->expectConstraint(fn () => DB::table('maintenance_orders')->where('id', $order->id)->update(['title' => 'Mutation SQL interdite']));
        $historyId = DB::table('maintenance_status_histories')->where('maintenance_order_id', $order->id)->value('id');
        $this->expectConstraint(fn () => DB::table('maintenance_status_histories')->where('id', $historyId)->update(['reason' => 'Mutation interdite']));
        $this->expectConstraint(fn () => DB::table('maintenance_status_histories')->where('id', $historyId)->delete());

        $this->inTenant($f, fn () => app(CompleteMaintenanceOrder::class)->handle($order, ['actual_cost' => '0.00', 'mileage' => 1100, 'return_to_active' => false], $f['owner']->id));
        $this->expectConstraint(fn () => DB::table('maintenance_orders')->where('id', $order->id)->update(['title' => 'Terminal modifié']));
        $this->expectConstraint(fn () => DB::table('maintenance_orders')->where('id', $order->id)->delete());
        $this->assertSame(1, (int) DB::scalar("select count(*) from pg_constraint where conname = 'vehicle_blocks_no_active_overlap_excl'"));
        $this->assertSame(2, (int) DB::scalar("select count(*) from pg_trigger where not tgisinternal and tgname in ('maintenance_histories_append_only','maintenance_orders_cycle_immutability')"));
    }

    public function test_maintenance_documents_are_private_versioned_and_cross_agency_protected(): void
    {
        $f = $this->fixture();
        $order = $this->order($f);
        $this->actingAs($f['owner'])->post(route('maintenance.documents.store', $order), [
            'document_type' => DocumentType::MaintenanceQuote->value,
            'title' => 'Devis atelier fictif',
            'is_sensitive' => '1',
            'file' => $this->pdf('devis.pdf'),
        ])->assertRedirect();

        $document = $this->inTenant($f, fn () => $order->documents()->firstOrFail());
        $this->assertSame('maintenance_order', $document->documentable_type);
        $firstVersion = $this->inTenant($f, fn () => $document->currentVersion()->firstOrFail());
        Storage::disk(config('documents.disk'))->assertExists($firstVersion->stored_path);
        $this->assertSame(64, strlen($firstVersion->sha256));
        $this->post(route('documents.versions.store', $document), ['file' => $this->pdf('devis-v2.pdf')])->assertRedirect();
        $this->assertSame(2, $this->inTenant($f, fn () => $document->versions()->count()));
        $this->get(route('documents.show', $document))->assertOk()->assertSee('Document privé');

        $managerB = $f['users']['agency-manager-b'];
        $response = $this->actingAs($managerB)->post(route('maintenance.documents.store', $order), [
            'document_type' => DocumentType::MaintenanceInterventionReport->value,
            'title' => 'Tentative autre agence', 'is_sensitive' => '1', 'file' => $this->pdf('interdit.pdf'),
        ]);
        $this->assertContains($response->getStatusCode(), [403, 404]);
        $this->get('/storage/document-maintenance.pdf')->assertNotFound();
        $this->assertFalse(collect(Route::getRoutes())->contains(fn ($route) => str_starts_with($route->uri(), 'storage/{')));
    }

    public function test_rbac_lists_dashboard_and_blade_cycle_are_agency_scoped(): void
    {
        $f = $this->fixture();
        $orderA = $this->order($f);
        $orderB = $this->order($f, CarbonImmutable::now()->addDays(7)->startOfHour(), 'b');
        $expected = [
            'owner' => [200, 200], 'agency-manager' => [200, 200], 'fleet-manager' => [200, 200],
            'rental-agent' => [200, 403], 'accountant' => [403, 403], 'viewer-auditor' => [200, 403],
        ];

        foreach ($expected as $key => [$showStatus, $editStatus]) {
            $user = $key === 'owner' ? $f['owner'] : $f['users'][$key];
            $this->actingAs($user)->get(route('maintenance.show', $orderA))->assertStatus($showStatus);
            $this->get(route('maintenance.edit', $orderA))->assertStatus($editStatus);
        }

        $this->actingAs($f['users']['agency-manager'])->get(route('maintenance.index'))->assertOk()->assertSee($orderA->maintenance_number)->assertDontSee($orderB->maintenance_number);
        $this->get(route('dashboard'))->assertOk()->assertSee('Pilotage maintenance')->assertDontSee($orderB->maintenance_number);

        $this->actingAs($f['owner']);
        $this->post(route('maintenance.approve', $orderA))->assertRedirect();
        $this->post(route('maintenance.start', $orderA))->assertRedirect();
        $this->post(route('maintenance.complete', $orderA), ['actual_cost' => '250.00', 'mileage' => 1250, 'return_to_active' => '1', 'reason' => 'Parcours Blade'])->assertRedirect();
        $this->get(route('maintenance.show', $orderA))->assertOk()->assertSee('Terminée')->assertSee('Dépense générée')->assertSee('Timeline immuable')->assertSee('Documents privés');
        $this->assertSame(1, $this->inTenant($f, fn () => AuditLog::where('action', 'maintenance.completed')->where('auditable_id', $orderA->id)->count()));
    }

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create(['name' => 'Tenant Maintenance C1']);
        $agencyA = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create(['name' => 'Agence C1 A']));
        $agencyB = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create(['name' => 'Agence C1 B']));
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => null, 'role_id' => Role::where('slug', 'tenant-owner')->value('id')]);
        $users = [];
        foreach (['agency-manager', 'fleet-manager', 'rental-agent', 'accountant', 'viewer-auditor'] as $role) {
            $users[$role] = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => $agencyA->id, 'role_id' => Role::where('slug', $role)->value('id')]);
        }
        $users['agency-manager-b'] = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => $agencyB->id, 'role_id' => Role::where('slug', 'agency-manager')->value('id')]);
        $f = ['tenant' => $tenant, 'agency_a' => $agencyA, 'agency_b' => $agencyB, 'owner' => $owner, 'users' => $users];

        $category = $this->inTenant($f, fn () => VehicleCategory::create(['code' => 'C1-'.uniqid(), 'name' => 'Maintenance C1', 'is_active' => true]));
        $vehicleA = $this->inTenant($f, fn () => app(CreateVehicle::class)->handle(['agency_id' => $agencyA->id, 'vehicle_category_id' => $category->id, 'registration_number' => 'C1-A-'.uniqid(), 'brand' => 'Dacia', 'model' => 'Logan', 'production_year' => 2025, 'fuel_type' => 'diesel', 'transmission' => 'manual', 'current_mileage' => 1000], $owner->id));
        $vehicleB = $this->inTenant($f, fn () => app(CreateVehicle::class)->handle(['agency_id' => $agencyB->id, 'vehicle_category_id' => $category->id, 'registration_number' => 'C1-B-'.uniqid(), 'brand' => 'Renault', 'model' => 'Clio', 'production_year' => 2025, 'fuel_type' => 'petrol', 'transmission' => 'manual', 'current_mileage' => 2000], $owner->id), 'b');

        return [...$f, 'category' => $category, 'vehicle_a' => $vehicleA, 'vehicle_b' => $vehicleB];
    }

    private function order(array $f, ?CarbonImmutable $start = null, string $agency = 'a'): MaintenanceOrder
    {
        $start ??= CarbonImmutable::now()->addDays(2)->startOfHour();
        $agencyModel = $f['agency_'.$agency];
        $vehicle = $f['vehicle_'.$agency];

        return $this->inTenant($f, fn () => app(CreateMaintenanceOrder::class)->handle([
            'agency_id' => $agencyModel->id, 'vehicle_id' => $vehicle->id, 'maintenance_type' => 'preventive',
            'priority' => 'normal', 'title' => 'Maintenance '.$agency, 'scheduled_start_at' => $start,
            'scheduled_end_at' => $start->addHours(2), 'mileage_at_opening' => $vehicle->current_mileage,
            'estimated_cost' => '1000.00', 'supplier' => 'Atelier fictif',
        ], $f['owner']->id), $agency);
    }

    private function editData(array $f, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return [
            'vehicle_id' => $f['vehicle_a']->id, 'maintenance_type' => 'repair', 'priority' => 'high',
            'title' => 'Révision complète', 'description' => 'Intervention planifiée',
            'scheduled_start_at' => $start->toIso8601String(), 'scheduled_end_at' => $end->toIso8601String(),
            'mileage_at_opening' => 1000, 'estimated_cost' => '1450.75', 'supplier' => 'Atelier de démonstration',
        ];
    }

    private function inTenant(array $fixture, callable $callback, string $agency = 'a'): mixed
    {
        return app(TenantContext::class)->run($fixture['tenant'], $callback, $fixture['agency_'.$agency]->id);
    }

    private function expectValidation(callable $callback, string $key): void
    {
        try {
            $callback();
            $this->fail('Une erreur métier était attendue.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }

    private function expectConstraint(callable $callback): void
    {
        DB::beginTransaction();

        try {
            $callback();
            DB::rollBack();
            $this->fail('La contrainte PostgreSQL aurait dû refuser la mutation.');
        } catch (QueryException $exception) {
            DB::rollBack();
            $this->assertSame('23514', $exception->getCode());
        }
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\nDocument de maintenance fictif\n%%EOF");
    }
}
