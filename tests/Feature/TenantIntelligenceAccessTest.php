<?php

namespace Tests\Feature;

use App\Actions\Intelligence\QueueFleetReallocationRun;
use App\Actions\Platform\ProvisionTenant;
use App\Actions\Platform\SetTenantIntelligenceAccess;
use App\Enums\IntelligenceCapability;
use App\Enums\TenantStatus;
use App\Exceptions\TenantIntelligenceUnavailableException;
use App\Models\FleetReallocationRun;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantIntelligenceAccess as TenantIntelligenceAccessModel;
use App\Models\User;
use App\Support\Intelligence\IntelligenceCapabilityCatalog;
use App\Support\Intelligence\TenantIntelligenceAccess;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class TenantIntelligenceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_catalog_contains_exactly_the_six_integrated_business_capabilities(): void
    {
        $catalog = app(IntelligenceCapabilityCatalog::class);
        $definitions = $catalog->all();

        $this->assertSame(
            array_map(fn (IntelligenceCapability $capability): string => $capability->value, IntelligenceCapability::cases()),
            array_keys($definitions),
        );
        $this->assertCount(6, $definitions);
        foreach ($definitions as $definition) {
            $this->assertNotSame('', $definition['label']);
            $this->assertNotSame('', $definition['usage']);
            $this->assertNotSame('', $definition['description']);
            $this->assertDoesNotMatchRegularExpression(
                '/sha|checkpoint|python|traceback|artefact|seuil|digest/i',
                implode(' ', [$definition['label'], $definition['usage'], $definition['description']]),
            );
        }
        $this->assertArrayNotHasKey('cancellation_risk', $definitions);
        $this->assertArrayNotHasKey('vehicle_brand', $definitions);
    }

    public function test_provisioned_tenant_starts_with_all_six_capabilities_disabled(): void
    {
        $platform = $this->platformAdmin();
        $result = app(ProvisionTenant::class)->handle([
            'name' => 'Entreprise Nouvelle',
            'slug' => 'entreprise-nouvelle',
            'legal_name' => 'Entreprise Nouvelle SARL',
            'email' => 'contact@example.test',
            'phone' => null,
            'address' => 'Adresse de démonstration',
            'currency' => 'MAD',
            'timezone' => 'Africa/Casablanca',
            'agency_code' => 'CASA',
            'agency_name' => 'Agence principale',
            'agency_email' => null,
            'agency_phone' => null,
            'agency_address' => 'Adresse de démonstration',
            'owner_name' => 'Propriétaire Démo',
            'owner_email' => 'owner@example.test',
        ], $platform->getKey());

        $accesses = TenantIntelligenceAccessModel::query()
            ->where('tenant_id', $result['tenant']->getKey())
            ->get();

        $this->assertCount(6, $accesses);
        $this->assertTrue($accesses->every(fn (TenantIntelligenceAccessModel $access): bool => ! $access->enabled));
        $this->assertTrue($accesses->every(fn (TenantIntelligenceAccessModel $access): bool => $access->updated_by === $platform->getKey()));
    }

    public function test_migration_backfills_only_preexisting_tenants_as_enabled(): void
    {
        $tenant = Tenant::factory()->create();
        DB::statement('DROP TRIGGER tenant_intelligence_accesses_guard ON tenant_intelligence_accesses');
        DB::statement('DROP FUNCTION rentfleet_guard_tenant_intelligence_access()');
        DB::statement('DROP TABLE tenant_intelligence_accesses');

        $migration = require database_path('migrations/2026_09_02_000002_create_tenant_intelligence_accesses.php');
        $migration->up();

        $accesses = TenantIntelligenceAccessModel::query()->where('tenant_id', $tenant->getKey())->get();
        $this->assertCount(6, $accesses);
        $this->assertTrue($accesses->every(fn (TenantIntelligenceAccessModel $access): bool => $access->enabled));
        $this->assertTrue($accesses->every(fn (TenantIntelligenceAccessModel $access): bool => $access->updated_by === null));
    }

    public function test_effective_access_requires_global_runtime_active_tenant_and_enabled_row(): void
    {
        $tenant = Tenant::factory()->create();
        $context = app(TenantContext::class);
        $context->set($tenant);
        $capability = IntelligenceCapability::VehicleColor;

        $this->assertTrue($this->service(true, true, $context)->usable($capability));
        $this->assertFalse($this->service(false, true, $context)->usable($capability));
        $this->assertFalse($this->service(true, false, $context)->usable($capability));

        TenantIntelligenceAccessModel::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('capability', $capability->value)
            ->update(['enabled' => false, 'changed_at' => now()]);
        $this->assertFalse($this->service(true, true, $context)->usable($capability));

        TenantIntelligenceAccessModel::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('capability', $capability->value)
            ->update(['enabled' => true, 'changed_at' => now()]);
        $tenant->forceFill(['status' => TenantStatus::Suspended])->save();
        $this->assertFalse($this->service(true, true, $context)->usable($capability));
    }

    public function test_platform_action_is_authorized_transactional_audited_and_rows_cannot_be_deleted(): void
    {
        $tenant = Tenant::factory()->create();
        $platform = $this->platformAdmin();
        $capability = IntelligenceCapability::VehicleDamage;
        $action = app(SetTenantIntelligenceAccess::class);

        $access = $action->handle($tenant, $capability, false, $platform);
        $this->assertFalse($access->enabled);
        $this->assertSame($platform->getKey(), $access->updated_by);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->getKey(),
            'action' => 'platform.intelligence_access.updated',
        ]);

        $tenantOwnerRole = Role::query()
            ->whereNull('tenant_id')
            ->where('slug', 'tenant-owner')
            ->firstOrFail();
        $tenantUser = User::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'agency_id' => null,
            'role_id' => $tenantOwnerRole->getKey(),
            'is_platform_admin' => false,
        ]);
        try {
            $action->handle($tenant, $capability, true, $tenantUser);
            $this->fail('Un utilisateur tenant ne doit pas modifier les accès Intelligence.');
        } catch (AuthorizationException) {
            $this->assertFalse($access->fresh()->enabled);
        }

        $this->expectException(QueryException::class);
        DB::table('tenant_intelligence_accesses')->where('id', $access->getKey())->delete();
    }

    public function test_disabled_capability_blocks_before_run_job_and_business_audit(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->withIntelligenceDisabled()->create();
        $role = Role::query()->whereNull('tenant_id')->where('slug', 'tenant-owner')->firstOrFail();
        $actor = User::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'agency_id' => null,
            'role_id' => $role->getKey(),
            'is_active' => true,
        ]);
        app(TenantContext::class)->set($tenant);
        $catalog = Mockery::mock(IntelligenceCapabilityCatalog::class);
        $catalog->shouldNotReceive('globallyEnabled');
        $catalog->shouldNotReceive('runtimeReady');
        $this->app->instance(IntelligenceCapabilityCatalog::class, $catalog);

        try {
            app(QueueFleetReallocationRun::class)->handle($actor, 1);
            $this->fail('Une capacité désactivée ne doit pas créer de run.');
        } catch (TenantIntelligenceUnavailableException $exception) {
            $this->assertSame('Cette fonctionnalité n’est pas disponible pour cette entreprise.', $exception->getMessage());
        }

        $this->assertSame(0, FleetReallocationRun::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'prediction.fleet_reallocation.run_queued']);
    }

    private function service(
        bool $globallyEnabled,
        bool $runtimeReady,
        TenantContext $context,
    ): TenantIntelligenceAccess {
        $catalog = Mockery::mock(IntelligenceCapabilityCatalog::class);
        $catalog->shouldReceive('globallyEnabled')->zeroOrMoreTimes()->andReturn($globallyEnabled);
        $catalog->shouldReceive('runtimeReady')->zeroOrMoreTimes()->andReturn($runtimeReady);

        return new TenantIntelligenceAccess($context, $catalog);
    }

    private function platformAdmin(): User
    {
        return User::factory()->create([
            'tenant_id' => null,
            'agency_id' => null,
            'role_id' => null,
            'is_active' => true,
            'is_platform_admin' => true,
        ]);
    }
}
