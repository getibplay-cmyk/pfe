<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_search_and_favorites_are_scoped_to_tenant_and_agency(): void
    {
        $owner = $this->createTenantOwner();
        [$agency, $customer] = $this->customer($owner, 'Trouvable');
        [$otherAgency, $other] = $this->customer($owner, 'Trouvable ailleurs');
        $foreignOwner = $this->createTenantOwner();
        [, $foreign] = $this->customer($foreignOwner, 'Trouvable étranger');
        $agent = User::factory()->create(['tenant_id' => $owner->tenant_id, 'agency_id' => $agency->id, 'role_id' => Role::where('slug', 'agency-manager')->value('id')]);
        $response = $this->actingAs($agent)->getJson(route('workspace.index', ['q' => 'Trouvable']))->assertOk();
        $this->assertSame([$customer->id], collect($response->json('results'))->pluck('id')->all());
        $response->assertDontSee('identity_number')->assertDontSee('email')->assertDontSee('phone');
        $this->post(route('workspace.favorite'), ['kind' => 'customers', 'id' => $foreign->id])->assertNotFound();
        $this->post(route('workspace.favorite'), ['kind' => 'customers', 'id' => $other->id])->assertNotFound();
        $this->post(route('workspace.favorite'), ['kind' => 'customers', 'id' => $customer->id])->assertRedirect();
        $this->actingAs($agent->fresh())->get(route('workspace.index'))->assertOk()->assertSee($customer->displayName())->assertDontSee($other->displayName());
        $agent->forceFill(['agency_id' => $otherAgency->id])->save();
        $this->actingAs($agent->fresh())->get(route('workspace.index'))->assertDontSee($customer->displayName());
    }

    public function test_search_rejects_tenant_input_and_treats_wildcards_as_literals(): void
    {
        $owner = $this->createTenantOwner();
        $this->customer($owner, 'Client normal');
        $this->actingAs($owner)->getJson(route('workspace.index', ['tenant_id' => $owner->tenant_id]))->assertUnprocessable();
        $this->getJson(route('workspace.index', ['q' => '%%']))->assertOk()->assertJsonPath('results', []);
        $this->getJson(route('workspace.index', ['q' => str_repeat('a', 81)]))->assertUnprocessable();
        $platform = User::factory()->create(['is_platform_admin' => true, 'tenant_id' => null]);
        $this->actingAs($platform)->getJson(route('workspace.index', ['q' => 'Client']))->assertForbidden();
    }

    public function test_saved_filters_have_allowlisted_routes_and_cannot_expand_agency_scope(): void
    {
        $owner = $this->createTenantOwner();
        [$agency] = $this->customer($owner, 'Client');
        [$otherAgency] = $this->customer($owner, 'Autre');
        $agent = User::factory()->create(['tenant_id' => $owner->tenant_id, 'agency_id' => $agency->id, 'role_id' => Role::where('slug', 'agency-manager')->value('id')]);
        $this->actingAs($agent)->post(route('workspace.filters.store'), ['screen' => 'https://evil.example', 'label' => 'Piège', 'filters' => []])->assertSessionHasErrors('screen');
        $this->post(route('workspace.filters.store'), ['screen' => 'fleet.planning.index', 'label' => 'Agence', 'filters' => ['agency_id' => $otherAgency->id]])->assertSessionHasErrors('agency_id');
        $this->post(route('workspace.filters.store'), ['screen' => 'fleet.planning.index', 'label' => 'Ma semaine', 'filters' => ['days' => 7, 'agency_id' => $agency->id, 'redirect' => 'https://evil.example']])->assertRedirect(route('workspace.index'));
        $filters = $agent->fresh()->workspace_preferences['filters'];
        $this->assertCount(1, $filters);
        $this->assertArrayNotHasKey('redirect', $filters[0]['filters']);
        $this->actingAs($owner)->delete(route('workspace.filters.destroy'), ['id' => $filters[0]['id']])->assertRedirect();
        $this->assertCount(1, $agent->fresh()->workspace_preferences['filters']);
    }

    public function test_language_is_persisted_and_rtl_is_rendered_without_changing_customer_text(): void
    {
        $owner = $this->createTenantOwner();
        [, $customer] = $this->customer($owner, 'Nom original');
        $this->actingAs($owner)->post(route('locale.update'), ['locale' => 'ar'])->assertRedirect();
        $this->assertSame('ar', $owner->fresh()->locale);
        $this->actingAs($owner->fresh())->get(route('workspace.index', ['q' => 'Nom original']))->assertOk()
            ->assertHeader('Content-Language', 'ar')->assertSee('dir="rtl"', false)->assertSee($customer->displayName())
            ->assertSee('البحث والمفضلة')->assertSee('مفضلتي')->assertDontSee('Mes favoris');
        $this->get(route('customers.create'))->assertOk()->assertSee('الاسم الشخصي');
        $this->post(route('locale.update'), ['locale' => '../../bad'])->assertSessionHasErrors('locale');
        $this->assertSame('fr', app()->getLocale());
    }

    private function customer(User $owner, string $name): array
    {
        return app(TenantContext::class)->run($owner->tenant_id, function () use ($name) {
            $agency = Agency::factory()->create();
            $customer = Customer::create(['agency_id' => $agency->id, 'customer_type' => 'individual', 'first_name' => $name, 'last_name' => 'Test']);

            return [$agency, $customer];
        });
    }
}
