<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class J13ConsultativeIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['intelligence.export_hmac_key' => str_repeat('j13-test-only-', 4)]);
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_authorized_page_uses_business_facing_copy_for_intelligent_features(): void
    {
        $response = $this->actingAs($this->user('tenant-owner'))->get(route('intelligence.index'));

        $response->assertOk()
            ->assertSee('Prévision de demande D+1 à D+7')
            ->assertSee('Couleur suggérée')
            ->assertSee('Analyse des dommages')
            ->assertSee('Immatriculation détectée')
            ->assertSee('Usages atypiques')
            ->assertSee('Principes d’utilisation')
            ->assertSee('Fonctionnalités en préparation');

        foreach (['HGB', 'ONNX', 'ANPR', 'Isolation Forest', 'benchmark', 'artefact', 'gate', 'feature flag', 'NO_OPERATIONAL_ACTION'] as $technicalTerm) {
            $response->assertDontSeeText($technicalTerm);
        }

        $this->assertSame(0, substr_count($response->getContent(), 'data-j13-module='));
        $response->assertDontSee('Ouvrir la démonstration isolée');
    }

    public function test_page_keeps_internal_model_lineage_out_of_the_agency_interface(): void
    {
        $response = $this->actingAs($this->user('viewer-auditor'))->get(route('intelligence.index'));

        $response->assertOk()
            ->assertSee('Un signal atypique attire l’attention d’un responsable')
            ->assertDontSee('robust_mad_top2')
            ->assertDontSee('rental_anomaly_iforest 0.1.0')
            ->assertDontSee('not_run_synthetic_contract_fixture')
            ->assertDontSee('J13')
            ->assertDontSee('solveur');
    }

    public function test_page_keeps_j12_closed_and_does_not_write_demo_or_operational_tables(): void
    {
        $user = $this->user('tenant-owner');
        $trackedTables = [
            'vehicles',
            'vehicle_blocks',
            'reservations',
            'rental_contracts',
            'maintenance_orders',
            'invoices',
            'payments',
        ];
        $before = collect($trackedTables)->mapWithKeys(
            static fn (string $table): array => [$table => DB::table($table)->count()],
        );

        $this->actingAs($user)->get(route('intelligence.index'))->assertOk();
        $this->actingAs($user)->get(route('intelligence.contract-demo.index'))->assertNotFound();

        $this->assertFalse(config('intelligence.contract_demo.enabled'));
        $this->assertDatabaseCount('ai_advisory_records_demo', 0);
        $this->assertDatabaseCount('ai_idempotency_keys_demo', 0);
        $this->assertDatabaseCount('ai_human_decisions_demo', 0);

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
        }
    }

    public function test_consultative_cards_remain_protected_by_prediction_view_permission(): void
    {
        $this->actingAs($this->user('rental-agent'))
            ->get(route('intelligence.index'))
            ->assertForbidden();

        $this->actingAs($this->user('fleet-manager'))
            ->get(route('intelligence.index'))
            ->assertOk()
            ->assertSee('Fonctionnalités en préparation');
    }

    private function user(string $roleSlug): User
    {
        $tenant = Tenant::factory()->create();
        $agency = app(TenantContext::class)->run(
            $tenant,
            fn (): Agency => Agency::factory()->create(),
        );

        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => $roleSlug === 'tenant-owner' ? null : $agency->id,
            'role_id' => Role::where('slug', $roleSlug)->value('id'),
            'must_change_password' => false,
        ]);
    }
}
