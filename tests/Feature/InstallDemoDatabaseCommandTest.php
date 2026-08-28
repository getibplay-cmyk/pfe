<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Tenant;
use Database\Seeders\RentFleetDemoV1HistoricalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InstallDemoDatabaseCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_installs_a_complete_demo_from_an_empty_schema_and_is_safe_to_rerun(): void
    {
        Storage::fake('local');

        $arguments = [
            '--expect-database' => 'rentfleet_test',
            '--no-interaction' => true,
        ];

        $this->artisan('rentfleet:demo:install', $arguments)->assertSuccessful();

        $this->assertSame(2, Tenant::query()->count());
        $this->assertSame(
            RentFleetDemoV1HistoricalSeeder::HISTORICAL_CONTRACTS,
            Reservation::withoutGlobalScopes()->where('reservation_number', 'like', 'RES-DEMO-V1-%')->count(),
        );

        $this->artisan('rentfleet:demo:install', $arguments)->assertSuccessful();

        $this->assertSame(2, Tenant::query()->count());
        $this->assertSame(
            RentFleetDemoV1HistoricalSeeder::HISTORICAL_CONTRACTS,
            Reservation::withoutGlobalScopes()->where('reservation_number', 'like', 'RES-DEMO-V1-%')->count(),
        );
    }

    public function test_it_refuses_a_target_that_does_not_match_the_resolved_database(): void
    {
        Storage::fake('local');

        $this->artisan('rentfleet:demo:install', [
            '--expect-database' => 'rentfleet_demo',
            '--no-interaction' => true,
        ])->assertFailed();

        $this->assertSame(0, Tenant::query()->count());
    }
}
