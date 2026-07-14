<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Lot00Test extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_available_and_registration_is_disabled(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/register')->assertNotFound();
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_dashboard(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('0');
    }

    public function test_health_reports_application_and_database(): void
    {
        $response = $this->getJson('/health')
            ->assertOk()
            ->assertExactJson([
                'application' => 'ok',
                'database' => 'ok',
                'status' => 'ok',
            ]);

        $this->assertStringNotContainsString('password', strtolower($response->getContent()));
        $this->assertStringNotContainsString('app_key', strtolower($response->getContent()));
    }

    public function test_suite_uses_the_dedicated_postgresql_database(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('rentfleet_test', DB::connection()->getDatabaseName());
        $this->assertSame(1, DB::scalar('select 1'));
    }
}
