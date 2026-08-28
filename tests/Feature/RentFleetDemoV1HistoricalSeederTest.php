<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\RentalContract;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Support\Intelligence\RentalAnomalyDataset;
use App\Support\Reporting\ReportCriteria;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RentFleetDemoV1HistoricalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RentFleetDemoV1HistoricalSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_v1_is_idempotent_coherent_and_ready_for_the_anomaly_runtime(): void
    {
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);

        $tenant = Tenant::where('slug', 'atlas-location-demo')->firstOrFail();
        $agencyIds = DB::table('agencies')->where('tenant_id', $tenant->id)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        app(TenantContext::class)->run($tenant, function () use ($tenant, $agencyIds): void {
            $reservations = Reservation::where('reservation_number', 'like', 'RES-DEMO-V1-%');
            $contracts = RentalContract::where('contract_number', 'like', 'CTR-DEMO-V1-%');

            $this->assertSame(RentFleetDemoV1HistoricalSeeder::HISTORICAL_CONTRACTS, $reservations->count());
            $this->assertSame(RentFleetDemoV1HistoricalSeeder::HISTORICAL_CONTRACTS, $contracts->count());
            $this->assertSame(RentFleetDemoV1HistoricalSeeder::PAID_CONTRACTS, (clone $contracts)->where('status', 'closed')->count());
            $this->assertSame(120, (clone $contracts)->where('status', 'returned')->count());
            $this->assertSame(240, DB::table('vehicle_inspections')->where('inspection_type', 'return')->where('status', 'completed')->whereIn('rental_contract_id', $contracts->pluck('id'))->count());
            $this->assertSame(180, Invoice::where('invoice_number', 'like', 'INV-DEMO-V1-%')->count());
            $this->assertSame(120, Invoice::where('invoice_number', 'like', 'INV-DEMO-V1-%')->where('status', 'paid')->count());
            $this->assertSame(60, Invoice::where('invoice_number', 'like', 'INV-DEMO-V1-%')->where('status', 'partially_paid')->count());
            $this->assertSame(180, DB::table('payments')->where('payment_number', 'like', 'PAY-DEMO-V1-%')->where('status', 'posted')->count());
            $this->assertSame(180, DB::table('payment_allocations')->whereIn('payment_id', DB::table('payments')->select('id')->where('payment_number', 'like', 'PAY-DEMO-V1-%'))->count());

            $criteria = new ReportCriteria(
                tenantId: $tenant->id,
                agencyIds: $agencyIds,
                startsAt: CarbonImmutable::parse('2025-02-01 00:00:00+00'),
                endsAt: CarbonImmutable::parse('2026-08-28 00:00:00+00'),
                timezone: 'UTC',
            );
            $this->assertSame(240, app(RentalAnomalyDataset::class)->query($criteria)->count());

            $sample = $reservations->orderBy('id')->firstOrFail();
            $this->assertSame(RentFleetDemoV1HistoricalSeeder::DATASET_VERSION, $sample->pricing_snapshot['dataset_version']);
            $this->assertTrue($sample->pricing_snapshot['fictional']);
            $this->assertSame(RentFleetDemoV1HistoricalSeeder::SOURCE_LICENSE, $sample->pricing_snapshot['source_profile']['license']);
        });

        $this->seed(RentFleetDemoV1HistoricalSeeder::class);
        $this->assertSame(240, Reservation::withoutGlobalScopes()->where('reservation_number', 'like', 'RES-DEMO-V1-%')->count());
        $this->assertSame(1, Reservation::withoutGlobalScopes()->where('reservation_number', 'like', 'RES-DEMO-V1-%')->distinct()->count('tenant_id'));
    }
}
