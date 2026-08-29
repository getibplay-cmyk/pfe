<?php

namespace Tests\Feature;

use App\Actions\Intelligence\ExecuteRentalUsageAnomalyRun;
use App\Enums\RentalContractStatus;
use App\Enums\RentalUsageAnomalyRunStatus;
use App\Jobs\RunRentalUsageAnomalyScreening;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\IntelligenceDatasetExportRun;
use App\Models\RentalContract;
use App\Models\RentalUsageAnomalyResult;
use App\Models\RentalUsageAnomalyReview;
use App\Models\RentalUsageAnomalyRun;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleInspection;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RentalUsageAnomalyIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
        Storage::fake('local');
        config([
            'intelligence.export_hmac_key' => str_repeat('rental-anomaly-test-key-', 2),
            'intelligence.rental_usage_anomaly.enabled' => true,
            'intelligence.rental_usage_anomaly.python_binary' => 'python',
        ]);
    }

    public function test_real_v11_snapshot_is_ranked_reviewed_and_never_mutates_business_tables(): void
    {
        $fixture = $this->fixture();
        $this->history($fixture, 200);
        $this->actingAs($fixture['user'])
            ->get(route('intelligence.export', $this->filters($fixture['agency'])))
            ->assertOk()
            ->streamedContent();
        $export = IntelligenceDatasetExportRun::withoutGlobalScopes()->firstOrFail();
        $snapshotRows = $this->snapshotRows($export);
        $this->assertCount(200, $snapshotRows);
        $businessBefore = $this->businessFingerprint();

        Queue::fake();
        $this->actingAs($fixture['user'])
            ->post(route('intelligence.rental-usage-anomalies.store', $export))
            ->assertRedirect();
        $run = RentalUsageAnomalyRun::withoutGlobalScopes()->firstOrFail();
        Queue::assertPushed(
            RunRentalUsageAnomalyScreening::class,
            fn (RunRentalUsageAnomalyScreening $job): bool => $job->runId === $run->run_id
                && $job->tenantId === $fixture['tenant']->id
                && $job->actorId === $fixture['user']->id
                && $job->queue === 'intelligence',
        );

        Process::fake(['*' => Process::result(output: $this->usableOutput($run, $export, $snapshotRows))]);
        (new RunRentalUsageAnomalyScreening($run->run_id, $run->tenant_id, $run->requested_by))
            ->handle(app(ExecuteRentalUsageAnomalyRun::class));

        $completed = RentalUsageAnomalyRun::withoutGlobalScopes()->findOrFail($run->id);
        $this->assertSame(RentalUsageAnomalyRunStatus::Succeeded, $completed->status);
        $this->assertSame('usable', $completed->data_status);
        $this->assertSame(4, $completed->candidate_count);
        $this->assertSame([1, 2, 4], collect($completed->budget_results)->pluck('selected_count')->all());
        $this->assertSame(4, RentalUsageAnomalyResult::withoutGlobalScopes()->count());
        $this->assertSame(2, RentalUsageAnomalyResult::withoutGlobalScopes()->where('primary_selected_010', true)->count());
        $this->assertSame($businessBefore, $this->businessFingerprint());
        $this->assertDatabaseHas('audit_logs', ['action' => 'prediction.rental_usage_anomaly.run_queued']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'prediction.rental_usage_anomaly.run_succeeded']);
        Process::assertRan(fn ($process): bool => is_array($process->command)
            && $process->command[0] === 'python'
            && $process->command[1] === config('intelligence.rental_usage_anomaly.runtime_script')
            && in_array('--snapshot-sha256', $process->command, true)
            && in_array('--minimum-rows', $process->command, true)
            && $process->timeout === 60);

        $page = $this->actingAs($fixture['user'])
            ->get(route('intelligence.rental-usage-anomalies.index', [
                'run' => $completed->run_id,
                'budget' => 100,
            ]))
            ->assertOk()
            ->assertSee('Usages de location atypiques')
            ->assertSee('robust_mad_top2')
            ->assertSee('Isolation Forest')
            ->assertSee('À revoir')
            ->assertSee('2')
            ->assertSee('Un score élevé n’est ni une preuve de fraude')
            ->assertDontSee($export->stored_path)
            ->assertDontSee($export->content_sha256);

        $result = RentalUsageAnomalyResult::withoutGlobalScopes()
            ->where('primary_rank', 1)
            ->firstOrFail();
        $this->actingAs($fixture['user'])
            ->post(route('intelligence.rental-usage-anomalies.reviews.store', $result), [
                'decision' => 'follow_up',
                'note' => 'Vérifier le justificatif de retour.',
                'budget' => 100,
            ])->assertRedirect();
        $this->actingAs($fixture['user'])
            ->post(route('intelligence.rental-usage-anomalies.reviews.store', $result), [
                'decision' => 'dismissed',
                'note' => 'Écart expliqué par le dossier.',
                'budget' => 100,
            ])->assertRedirect();
        $this->assertSame(2, RentalUsageAnomalyReview::withoutGlobalScopes()->count());
        $this->assertSame($businessBefore, $this->businessFingerprint());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'prediction.rental_usage_anomaly.human_review_recorded',
        ]);

        $review = RentalUsageAnomalyReview::withoutGlobalScopes()->firstOrFail();
        $this->assertPostgreSqlConstraint(fn () => DB::table('rental_usage_anomaly_reviews')
            ->where('id', $review->id)->update(['decision' => 'dismissed']));
        $this->assertPostgreSqlConstraint(fn () => DB::table('rental_usage_anomaly_results')
            ->where('id', $result->id)->delete());
        $this->assertPostgreSqlConstraint(fn () => DB::table('rental_usage_anomaly_runs')
            ->where('id', $completed->id)->update(['candidate_count' => 3]));
        $this->assertSame($businessBefore, $this->businessFingerprint());
        $this->assertStringNotContainsString('FRAUD', $page->getContent());
    }

    public function test_short_history_abstains_and_viewer_cannot_launch_or_review(): void
    {
        $fixture = $this->fixture();
        $this->history($fixture, 5);
        $this->actingAs($fixture['user'])
            ->get(route('intelligence.export', $this->filters($fixture['agency'])))
            ->assertOk()
            ->streamedContent();
        $export = IntelligenceDatasetExportRun::withoutGlobalScopes()->firstOrFail();
        Queue::fake();
        $this->actingAs($fixture['user'])
            ->post(route('intelligence.rental-usage-anomalies.store', $export))
            ->assertRedirect();
        $run = RentalUsageAnomalyRun::withoutGlobalScopes()->firstOrFail();
        Process::fake(['*' => Process::result(output: $this->insufficientOutput($run, $export))]);
        (new RunRentalUsageAnomalyScreening($run->run_id, $run->tenant_id, $run->requested_by))
            ->handle(app(ExecuteRentalUsageAnomalyRun::class));
        $completed = RentalUsageAnomalyRun::withoutGlobalScopes()->findOrFail($run->id);
        $this->assertSame('insufficient_data', $completed->data_status);
        $this->assertSame(0, $completed->candidate_count);
        $this->assertSame(0, RentalUsageAnomalyResult::withoutGlobalScopes()->count());

        $viewer = User::factory()->create([
            'tenant_id' => $fixture['tenant']->id,
            'agency_id' => $fixture['agency']->id,
            'role_id' => Role::where('slug', 'viewer-auditor')->value('id'),
            'must_change_password' => false,
        ]);
        $this->actingAs($viewer)
            ->get(route('intelligence.rental-usage-anomalies.index', ['run' => $completed->run_id]))
            ->assertOk()
            ->assertSee('Abstention')
            ->assertSee('minimum 200');
        $this->actingAs($viewer)
            ->post(route('intelligence.rental-usage-anomalies.store', $export))
            ->assertForbidden();
        $this->assertDatabaseHas('permissions', [
            'slug' => 'prediction.anomaly.review',
            'group' => 'prediction',
        ]);
        $this->assertSame(83, DB::table('migrations')->count());
    }

    public function test_missing_private_snapshot_returns_a_safe_validation_error(): void
    {
        $fixture = $this->fixture();
        $this->history($fixture, 1);
        $this->actingAs($fixture['user'])
            ->get(route('intelligence.export', $this->filters($fixture['agency'])))
            ->assertOk()
            ->streamedContent();
        $export = IntelligenceDatasetExportRun::withoutGlobalScopes()->firstOrFail();
        Storage::disk('local')->delete($export->stored_path);

        Queue::fake();
        $response = $this->actingAs($fixture['user'])
            ->from(route('intelligence.rental-usage-anomalies.index'))
            ->post(route('intelligence.rental-usage-anomalies.store', $export));

        $response
            ->assertRedirect(route('intelligence.rental-usage-anomalies.index'))
            ->assertSessionHasErrors([
                'export_run' => 'Le snapshot privé est absent ou son intégrité a changé. Régénérez un export RentFleet v1.1 avant de relancer l’analyse.',
            ]);
        $this->assertSame(0, RentalUsageAnomalyRun::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Entreprise test usages atypiques',
            'settings' => ['timezone' => 'Africa/Casablanca'],
        ]);
        $agency = app(TenantContext::class)->run(
            $tenant,
            fn () => Agency::factory()->create(['name' => 'Agence test usages atypiques']),
        );
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'role_id' => Role::where('slug', 'agency-manager')->value('id'),
            'must_change_password' => false,
        ]);

        return app(TenantContext::class)->run($tenant, function () use ($tenant, $agency, $user): array {
            $category = VehicleCategory::create([
                'code' => 'ANOMALY-'.str()->upper(str()->random(6)),
                'name' => 'Catégorie anomalie',
                'is_active' => true,
            ]);
            $customer = Customer::create([
                'agency_id' => $agency->id,
                'customer_type' => 'individual',
                'first_name' => 'Client',
                'last_name' => 'Test',
                'email' => 'anomaly@example.invalid',
                'verification_status' => 'verified',
            ]);
            $vehicle = Vehicle::create([
                'agency_id' => $agency->id,
                'vehicle_category_id' => $category->id,
                'registration_number' => 'ANOMALY-'.str()->upper(str()->random(8)),
                'vin' => 'ANOMALY'.str()->upper(str()->random(10)),
                'brand' => 'Marque test',
                'model' => 'Modèle test',
                'production_year' => 2026,
                'fuel_type' => 'petrol',
                'transmission' => 'manual',
                'current_mileage' => 1000,
            ]);

            return compact('tenant', 'agency', 'user', 'category', 'customer', 'vehicle');
        }, $agency->id);
    }

    private function history(array $fixture, int $count): void
    {
        app(TenantContext::class)->run($fixture['tenant'], function () use ($fixture, $count): void {
            for ($index = 1; $index <= $count; $index++) {
                $sequence = str_pad((string) $index, 4, '0', STR_PAD_LEFT);
                $reservation = Reservation::create([
                    'agency_id' => $fixture['agency']->id,
                    'customer_id' => $fixture['customer']->id,
                    'vehicle_category_id' => $fixture['category']->id,
                    'vehicle_id' => $fixture['vehicle']->id,
                    'reservation_number' => 'RES-ANOMALY-'.$sequence,
                    'starts_at' => '2026-08-10 10:00:00+00',
                    'ends_at' => '2026-08-11 10:00:00+00',
                    'status' => 'converted',
                    'subtotal' => '0.00',
                    'options_total' => '0.00',
                    'total_amount' => '0.00',
                    'deposit_amount' => '0.00',
                    'currency' => 'MAD',
                    'pricing_snapshot' => [],
                    'created_by' => $fixture['user']->id,
                ]);
                $lateHours = $index > $count - 4 ? 50 + $index : $index % 5;
                $actualReturn = CarbonImmutable::parse('2026-08-11 10:00:00+00')->addHours($lateHours);
                $contract = RentalContract::create([
                    'agency_id' => $fixture['agency']->id,
                    'reservation_id' => $reservation->id,
                    'customer_id' => $fixture['customer']->id,
                    'vehicle_id' => $fixture['vehicle']->id,
                    'contract_number' => 'CTR-ANOMALY-'.$sequence,
                    'status' => RentalContractStatus::Returned,
                    'expected_start_at' => '2026-08-10 10:00:00+00',
                    'expected_return_at' => '2026-08-11 10:00:00+00',
                    'actual_start_at' => '2026-08-10 10:00:00+00',
                    'actual_return_at' => $actualReturn,
                    'start_mileage' => 1000,
                    'return_mileage' => 1100 + $index,
                    'start_fuel_level' => '80.00',
                    'return_fuel_level' => (string) max(0, 70 - ($index % 20)).'.00',
                    'rental_subtotal' => '0.00',
                    'additional_charges_total' => '0.00',
                    'total_amount' => '0.00',
                    'deposit_required' => '0.00',
                    'currency' => 'MAD',
                    'returned_at' => $actualReturn,
                    'created_by' => $fixture['user']->id,
                ]);
                VehicleInspection::create([
                    'agency_id' => $fixture['agency']->id,
                    'rental_contract_id' => $contract->id,
                    'vehicle_id' => $fixture['vehicle']->id,
                    'inspection_type' => 'return',
                    'status' => 'completed',
                    'inspected_at' => $actualReturn,
                    'mileage' => $contract->return_mileage,
                    'fuel_level' => $contract->return_fuel_level,
                    'completed_by' => $fixture['user']->id,
                    'completed_at' => $actualReturn,
                    'created_by' => $fixture['user']->id,
                ]);
            }
        }, $fixture['agency']->id);
    }

    /** @return list<array<string, string>> */
    private function snapshotRows(IntelligenceDatasetExportRun $export): array
    {
        $content = Storage::disk('local')->get($export->stored_path);
        $lines = preg_split('/\r\n|\n|\r/', substr($content, 3), -1, PREG_SPLIT_NO_EMPTY);
        $headers = str_getcsv(array_shift($lines), ';', '"', '');

        return array_map(
            fn (string $line): array => array_combine($headers, str_getcsv($line, ';', '"', '')),
            $lines,
        );
    }

    /** @param list<array<string, string>> $snapshotRows */
    private function usableOutput(
        RentalUsageAnomalyRun $run,
        IntelligenceDatasetExportRun $export,
        array $snapshotRows,
    ): string {
        $rows = [];
        foreach (array_slice($snapshotRows, 0, 4) as $offset => $source) {
            $rank = $offset + 1;
            $selected = match ($rank) {
                1 => [50, 100, 200],
                2 => [100, 200],
                default => [200],
            };
            $rows[] = [
                'row_id' => $source['row_id'],
                'agency_key' => $source['agency_key'],
                'contract_key' => $source['contract_key'],
                'event_at' => $source['event_at'],
                'features' => [
                    'late_hours' => $source['late_hours'],
                    'km_per_day' => $source['km_per_day'],
                    'fuel_drop_pct' => $source['fuel_drop_pct'],
                ],
                'primary' => [
                    'score' => 10.0 - $rank,
                    'rank' => $rank,
                    'selected_budgets' => $selected,
                    'factors' => [
                        ['feature' => 'late_hours', 'value' => $source['late_hours'], 'median' => 2.0, 'mad' => 1.0, 'positive_robust_deviation' => 4.0],
                        ['feature' => 'km_per_day', 'value' => $source['km_per_day'], 'median' => 100.0, 'mad' => 10.0, 'positive_robust_deviation' => 3.0],
                    ],
                ],
                'challenger' => [
                    'score' => 1.0 - ($rank / 10),
                    'rank' => $rank,
                    'selected_budgets' => $selected,
                ],
            ];
        }

        return json_encode([
            'schema_version' => '1.0.0',
            'run_id' => $run->run_id,
            'source' => [
                'schema_version' => $export->schema_version,
                'dataset_version' => $export->dataset_version,
                'sha256' => $export->content_sha256,
                'byte_size' => $export->byte_size,
                'row_count' => $export->row_count,
            ],
            'execution' => [
                'compute' => 'CPU',
                'primary' => ['name' => 'robust_mad_top2', 'version' => '1.0.0'],
                'challenger' => ['name' => 'isolation_forest', 'version' => '1.0.0'],
                'random_state' => 20260824,
                'runtime_sha256' => $run->runtime_sha256,
                'minimum_rows' => 200,
                'default_budget_basis_points' => 100,
                'status' => 'usable',
            ],
            'safety' => [
                'human_review_required' => true,
                'automatic_actions_allowed' => false,
                'operational_effect' => 'NO_OPERATIONAL_ACTION',
                'forbidden_actions' => ['SANCTION', 'FEE_OR_CHARGE', 'FRAUD_ACCUSATION', 'CONTRACT_MUTATION'],
            ],
            'budgets' => [
                ['basis_points' => 50, 'requested_rate' => 0.005, 'selected_count' => 1, 'realized_rate' => 0.005, 'primary_cutoff' => 9.0, 'challenger_cutoff' => 0.9, 'agreement_count' => 1, 'union_count' => 1, 'jaccard' => 1.0],
                ['basis_points' => 100, 'requested_rate' => 0.01, 'selected_count' => 2, 'realized_rate' => 0.01, 'primary_cutoff' => 8.0, 'challenger_cutoff' => 0.8, 'agreement_count' => 2, 'union_count' => 2, 'jaccard' => 1.0],
                ['basis_points' => 200, 'requested_rate' => 0.02, 'selected_count' => 4, 'realized_rate' => 0.02, 'primary_cutoff' => 6.0, 'challenger_cutoff' => 0.6, 'agreement_count' => 4, 'union_count' => 4, 'jaccard' => 1.0],
            ],
            'rows' => $rows,
        ], JSON_THROW_ON_ERROR);
    }

    private function insufficientOutput(
        RentalUsageAnomalyRun $run,
        IntelligenceDatasetExportRun $export,
    ): string {
        return json_encode([
            'schema_version' => '1.0.0',
            'run_id' => $run->run_id,
            'source' => [
                'schema_version' => $export->schema_version,
                'dataset_version' => $export->dataset_version,
                'sha256' => $export->content_sha256,
                'byte_size' => $export->byte_size,
                'row_count' => $export->row_count,
            ],
            'execution' => [
                'compute' => 'CPU',
                'primary' => ['name' => 'robust_mad_top2', 'version' => '1.0.0'],
                'challenger' => ['name' => 'isolation_forest', 'version' => '1.0.0'],
                'random_state' => 20260824,
                'runtime_sha256' => $run->runtime_sha256,
                'minimum_rows' => 200,
                'default_budget_basis_points' => 100,
                'status' => 'insufficient_data',
                'reason' => 'MINIMUM_HISTORY_NOT_REACHED',
            ],
            'safety' => [
                'human_review_required' => true,
                'automatic_actions_allowed' => false,
                'operational_effect' => 'NO_OPERATIONAL_ACTION',
                'forbidden_actions' => ['SANCTION', 'FEE_OR_CHARGE', 'FRAUD_ACCUSATION', 'CONTRACT_MUTATION'],
            ],
            'budgets' => [],
            'rows' => [],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function businessFingerprint(): array
    {
        return [
            'contracts' => DB::table('rental_contracts')
                ->orderBy('id')
                ->get(['id', 'status', 'additional_charges_total', 'total_amount', 'updated_at'])
                ->map(fn ($row): array => (array) $row)
                ->all(),
            'charges' => DB::table('contract_charges')->count(),
            'invoices' => DB::table('invoices')->count(),
            'payments' => DB::table('payments')->count(),
            'damages' => DB::table('damage_reports')->count(),
        ];
    }

    /** @return array<string, int|string> */
    private function filters(Agency $agency): array
    {
        return [
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31',
            'agency_id' => $agency->id,
        ];
    }

    private function assertPostgreSqlConstraint(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('PostgreSQL devait refuser cette opération.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', (string) $exception->getCode());
        }
    }
}
