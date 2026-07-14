<?php

namespace Tests\Feature;

use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeploymentHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_and_doctor_confirm_release_prerequisites_without_secrets(): void
    {
        $this->seed(RolesPermissionsSeeder::class);

        $health = $this->getJson('/health')->assertOk()->assertJsonPath('status', 'ok');
        $this->assertStringNotContainsString('password', strtolower($health->getContent()));
        $this->assertStringNotContainsString('app_key', strtolower($health->getContent()));

        $this->artisan('rentfleet:doctor', ['--json' => true])
            ->expectsOutputToContain('"status": "ok"')
            ->assertSuccessful();

        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('rentfleet_test', DB::connection()->getDatabaseName());
    }

    public function test_release_commands_and_build_artifact_are_present(): void
    {
        $this->assertFileExists(app_path('Console/Commands/RentFleetDoctor.php'));
        $this->assertFileExists(base_path('scripts/deploy-check.ps1'));
        $this->assertFileExists(public_path('build/manifest.json'));
        $this->assertStringContainsString('route:cache', file_get_contents(base_path('scripts/deploy-check.ps1')));
        $this->assertStringContainsString('event:cache', file_get_contents(base_path('scripts/deploy-check.ps1')));
    }
}
