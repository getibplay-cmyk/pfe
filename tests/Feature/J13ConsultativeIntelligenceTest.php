<?php

namespace Tests\Feature;

use App\Enums\J11AdvisoryModule;
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

    public function test_authorized_page_renders_exactly_four_read_only_consultative_cards(): void
    {
        $response = $this->actingAs($this->user('tenant-owner'))->get(route('intelligence.index'));

        $response->assertOk()
            ->assertSee('J13 · preuves consultatives désactivées')
            ->assertSee('Mode consultatif fermé')
            ->assertSee('décision humaine auditée')
            ->assertSee('NO_OPERATIONAL_ACTION')
            ->assertSee('Feature flag : désactivé · SaaS : non · Production : non');

        foreach (J11AdvisoryModule::cases() as $module) {
            $response->assertSee($module->label())
                ->assertSee($module->gateDecision())
                ->assertSee('data-j13-module="'.$module->value.'"', false);
        }

        $this->assertSame(4, substr_count($response->getContent(), 'data-j13-module='));
        $response->assertDontSee('Ouvrir la démonstration isolée');
    }

    public function test_page_distinguishes_public_j9_legacy_iforest_and_non_computed_fixture(): void
    {
        $response = $this->actingAs($this->user('viewer-auditor'))->get(route('intelligence.index'));

        $response->assertOk()
            ->assertSee('robust_mad_top2')
            ->assertSee('rental_anomaly_iforest 0.1.0')
            ->assertSee('not_run_synthetic_contract_fixture')
            ->assertSee('Distinct de J9 et interdit dans J13')
            ->assertSee('Aucun modèle ni solveur exécuté')
            ->assertDontSee('Le modèle rental_anomaly_iforest 0.1.0 a été validé');
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
            ->assertSee('J13 · preuves consultatives désactivées');
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
