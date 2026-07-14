<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Driver;
use App\Models\PricingRule;
use App\Models\RentalContract;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleBlock;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DemoTenancySeeder;
use Database\Seeders\Lot02DemoSeeder;
use Database\Seeders\Lot03DemoSeeder;
use Database\Seeders\Lot04DemoSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CrossTenantCriticalResourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_tenant_cannot_resolve_any_critical_resource_from_first_tenant(): void
    {
        Storage::fake('local');
        $this->seed([
            RolesPermissionsSeeder::class,
            DemoTenancySeeder::class,
            Lot02DemoSeeder::class,
            Lot03DemoSeeder::class,
            Lot04DemoSeeder::class,
        ]);
        $primary = Tenant::where('slug', 'atlas-location-demo')->firstOrFail();
        $secondary = Tenant::where('slug', 'rif-mobilite-demo')->firstOrFail();

        $models = [
            Vehicle::class, Customer::class, Driver::class, Document::class,
            PricingRule::class, Reservation::class, VehicleBlock::class,
            RentalContract::class,
        ];

        foreach ($models as $modelClass) {
            $foreignId = $modelClass::withoutGlobalScopes()
                ->where('tenant_id', $primary->id)
                ->value('id');
            $this->assertNotNull($foreignId, $modelClass.' doit avoir une fixture du tenant principal.');

            $resolved = app(TenantContext::class)->run(
                $secondary,
                fn () => $modelClass::find($foreignId)
            );
            $this->assertNull($resolved, $modelClass.' a franchi la frontière tenant.');
        }
    }

    public function test_authorization_matrix_covers_platform_owner_manager_agent_inactive_and_foreign_user(): void
    {
        $this->seed(RolesPermissionsSeeder::class);
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $agencyA = app(TenantContext::class)->run($tenantA, fn () => Agency::factory()->create());
        $otherAgencyA = app(TenantContext::class)->run($tenantA, fn () => Agency::factory()->create());
        $agencyB = app(TenantContext::class)->run($tenantB, fn () => Agency::factory()->create());

        $user = fn (Tenant $tenant, string $role, ?Agency $agency = null, bool $active = true) => User::factory()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency?->id,
            'role_id' => Role::where('slug', $role)->value('id'),
            'is_active' => $active,
        ]);

        $platform = User::factory()->create(['tenant_id' => null, 'is_platform_admin' => true]);
        $owner = $user($tenantA, 'tenant-owner');
        $manager = $user($tenantA, 'agency-manager', $agencyA);
        $agent = $user($tenantA, 'rental-agent', $agencyA);
        $inactive = $user($tenantA, 'rental-agent', $agencyA, false);
        $foreignOwner = $user($tenantB, 'tenant-owner');

        $this->actingAs($platform)->get(route('platform.dashboard'))->assertOk();
        $this->actingAs($platform)->get(route('dashboard'))->assertForbidden();
        $this->actingAs($owner)->get(route('agencies.index'))->assertOk()->assertSee($agencyA->name)->assertSee($otherAgencyA->name)->assertDontSee($agencyB->name);
        $this->actingAs($manager)->get(route('agencies.index'))->assertOk()->assertSee($agencyA->name)->assertDontSee($otherAgencyA->name);
        $this->actingAs($agent)->get(route('reservations.index'))->assertOk();
        $this->actingAs($agent)->post(route('agencies.store'), ['code' => 'NO', 'name' => 'Interdit'])->assertForbidden();
        $this->actingAs($foreignOwner)->get(route('agencies.edit', $agencyA))->assertNotFound();

        auth()->logout();
        $this->post('/login', ['email' => $inactive->email, 'password' => 'password']);
        $this->assertGuest();
    }
}
