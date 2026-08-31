<?php

namespace Tests\Feature;

use App\Enums\RentalContractStatus;
use App\Enums\RentalUsageAnomalyRunStatus;
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
use App\Support\Intelligence\RentalUsageAnomaly\RentalUsageAnomalyContract;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RentalUsageAnomalyBusinessReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
        Storage::fake('local');
        config(['intelligence.rental_usage_anomaly.enabled' => false]);
    }

    public function test_business_queue_is_filtered_bounded_confidential_and_eager_loaded(): void
    {
        $fixture = $this->fixture();
        $run = $this->completeRun($fixture, 12.0);

        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = mb_strtolower($event->sql);
        });

        $page = $this->actingAs($fixture['users']['agency-manager'])
            ->get(route('intelligence.rental-usage-anomalies.index', [
                'agency' => $fixture['agency']->id,
                'date_from' => '2026-08-29',
                'date_to' => '2026-08-29',
                'review_state' => 'pending',
            ]))
            ->assertOk()
            ->assertSee('Usage atypique à vérifier')
            ->assertSee('2 cas dans la sélection actuelle')
            ->assertSee('Analyse du')
            ->assertSee('Indicateur statistique')
            ->assertSee('Facteurs observés')
            ->assertSee('12,00')
            ->assertSee('100,00')
            ->assertSee('km/jour')
            ->assertDontSee('robust_mad_top2')
            ->assertDontSee('isolation_forest')
            ->assertDontSee($run->run_id)
            ->assertDontSee($fixture['export']->run_id)
            ->assertDontSee($fixture['export']->stored_path)
            ->assertDontSee($fixture['export']->content_sha256);

        $lowerHtml = mb_strtolower($page->getContent());
        foreach (['fraude', 'culpabilité', 'faute', 'danger', 'sanction', 'jaccard', 'random state', 'sha256', 'csv'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $lowerHtml);
        }
        $this->assertSame(2, substr_count($page->getContent(), 'data-anomaly-case'));
        $this->assertSame(4, substr_count($page->getContent(), 'data-anomaly-factor'));
        $this->assertLessThanOrEqual(2, collect($queries)->filter(
            fn (string $sql): bool => str_contains($sql, 'rental_contracts'),
        )->count());
        $this->assertLessThanOrEqual(3, collect($queries)->filter(
            fn (string $sql): bool => str_contains($sql, 'rental_usage_anomaly_reviews'),
        )->count());

        $this->actingAs($fixture['users']['agency-manager'])
            ->get(route('intelligence.rental-usage-anomalies.index', ['review_state' => 'unknown']))
            ->assertRedirect()
            ->assertSessionHasErrors('review_state');
        $this->actingAs($fixture['users']['agency-manager'])
            ->get(route('intelligence.rental-usage-anomalies.index', [
                'date_from' => '2026-08-30',
                'date_to' => '2026-08-29',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('date_to');
        $this->actingAs($fixture['users']['agency-manager'])
            ->get(route('intelligence.rental-usage-anomalies.index', [
                'date_from' => '2025-01-01',
                'date_to' => '2026-08-29',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('date_to');

        foreach (['tenant', 'tenant_id', 'model', 'budget', 'score', 'run', 'unexpected'] as $field) {
            $this->actingAs($fixture['users']['agency-manager'])
                ->get(route('intelligence.rental-usage-anomalies.index', [$field => 'forbidden']))
                ->assertRedirect()
                ->assertSessionHasErrors($field);
        }

        $otherAgency = app(TenantContext::class)->run(
            $fixture['tenant'],
            fn () => Agency::factory()->create(['name' => 'Agence hors périmètre']),
        );
        $this->actingAs($fixture['users']['agency-manager'])
            ->get(route('intelligence.rental-usage-anomalies.index', ['agency' => $otherAgency->id]))
            ->assertForbidden();
    }

    public function test_contract_uses_latest_successful_canonical_result_and_review_labels(): void
    {
        $fixture = $this->fixture();
        $initialRun = $this->completeRun($fixture, 12.0);
        $canonicalContract = $fixture['contracts'][0];
        $nonCanonicalContract = $fixture['contracts'][2];
        $user = $fixture['users']['agency-manager'];
        $initialRawScore = RentalUsageAnomalyResult::withoutGlobalScopes()
            ->where('rental_usage_anomaly_run_id', $initialRun->id)
            ->where('rental_contract_id', $canonicalContract->id)
            ->value('primary_score');

        $this->actingAs($user)
            ->get(route('contracts.show', $canonicalContract))
            ->assertOk()
            ->assertSee('Usage atypique à vérifier')
            ->assertSee('Vérification humaine nécessaire')
            ->assertSee('12,00')
            ->assertDontSee((string) $initialRawScore)
            ->assertDontSee($initialRun->run_id);
        $this->actingAs($user)
            ->get(route('contracts.show', $nonCanonicalContract))
            ->assertOk()
            ->assertDontSee('Usage atypique à vérifier');
        $this->actingAs($user)
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertDontSee('Usage atypique à vérifier');

        $this->completeRun($fixture, 99.0, RentalUsageAnomalyRunStatus::Failed, CarbonImmutable::now()->addMinute());
        $this->actingAs($user)
            ->get(route('contracts.show', $canonicalContract))
            ->assertOk()
            ->assertSee('12,00')
            ->assertDontSee('99,00');

        $latestRun = $this->completeRun($fixture, 77.0, RentalUsageAnomalyRunStatus::Succeeded, CarbonImmutable::now()->addMinutes(2));
        $this->actingAs($user)
            ->get(route('contracts.show', $canonicalContract))
            ->assertOk()
            ->assertSee('77,00')
            ->assertDontSee('12,00');

        $this->actingAs($user)
            ->post(route('intelligence.rental-usage-anomalies.contract-reviews.store', $canonicalContract), [
                'decision' => 'follow_up',
            ])->assertRedirect();
        $this->actingAs($user)
            ->get(route('contracts.show', $canonicalContract))
            ->assertOk()
            ->assertSee('Usage atypique à vérifier');

        $this->actingAs($user)
            ->post(route('intelligence.rental-usage-anomalies.contract-reviews.store', $canonicalContract), [
                'decision' => 'needs_information',
            ])->assertRedirect();
        $this->actingAs($user)
            ->get(route('contracts.show', $canonicalContract))
            ->assertOk()
            ->assertSee('Vérification humaine nécessaire — informations complémentaires');

        $this->actingAs($user)
            ->post(route('intelligence.rental-usage-anomalies.contract-reviews.store', $canonicalContract), [
                'decision' => 'dismissed',
            ])->assertRedirect();
        $this->actingAs($user)
            ->get(route('contracts.show', $canonicalContract))
            ->assertOk()
            ->assertSee('Vérifié et écarté');
        $this->actingAs($user)
            ->get(route('intelligence.rental-usage-anomalies.index', [
                'agency' => $fixture['agency']->id,
                'date_from' => '2026-08-29',
                'date_to' => '2026-08-29',
                'review_state' => 'dismissed',
            ]))
            ->assertOk()
            ->assertSee($canonicalContract->contract_number)
            ->assertDontSee($fixture['contracts'][1]->contract_number);

        $latestResult = RentalUsageAnomalyResult::withoutGlobalScopes()
            ->where('rental_usage_anomaly_run_id', $latestRun->id)
            ->where('rental_contract_id', $canonicalContract->id)
            ->firstOrFail();
        $this->assertSame(3, RentalUsageAnomalyReview::withoutGlobalScopes()
            ->where('rental_usage_anomaly_result_id', $latestResult->id)
            ->count());

        $otherAgency = app(TenantContext::class)->run(
            $fixture['tenant'],
            fn () => Agency::factory()->create(['name' => 'Agence voisine']),
        );
        $otherAgencyUser = $this->user($fixture['tenant'], $otherAgency, 'agency-manager');
        $this->actingAs($otherAgencyUser)
            ->post(route('intelligence.rental-usage-anomalies.contract-reviews.store', $canonicalContract), [
                'decision' => 'follow_up',
            ])->assertNotFound();

        $otherTenant = Tenant::factory()->create();
        $otherTenantAgency = app(TenantContext::class)->run($otherTenant, fn () => Agency::factory()->create());
        $otherTenantUser = $this->user($otherTenant, $otherTenantAgency, 'agency-manager');
        $this->actingAs($otherTenantUser)
            ->get(route('contracts.show', $canonicalContract))
            ->assertNotFound();
    }

    public function test_reviews_reject_extra_input_remain_append_only_and_are_rate_limited(): void
    {
        $fixture = $this->fixture();
        $run = $this->completeRun($fixture, 12.0);
        $user = $fixture['users']['agency-manager'];
        $contract = $fixture['contracts'][0];
        $secondContract = $fixture['contracts'][1];
        $businessBefore = $this->businessFingerprint();
        $reviewAuditBefore = DB::table('audit_logs')
            ->where('action', 'prediction.rental_usage_anomaly.human_review_recorded')
            ->count();

        $this->actingAs($user)
            ->postJson(route('intelligence.rental-usage-anomalies.contract-reviews.store', $contract), [
                'decision' => 'invalid',
            ])->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->actingAs($user)
            ->postJson(route('intelligence.rental-usage-anomalies.contract-reviews.store', $contract), [
                'decision' => 'follow_up',
                'budget' => 100,
            ])->assertUnprocessable()->assertJsonValidationErrors('budget');
        $this->actingAs($user)
            ->postJson(route('intelligence.rental-usage-anomalies.contract-reviews.store', $contract), [
                'decision' => 'follow_up',
                'tenant_id' => 999,
                'agency_id' => 999,
                'reviewed_by' => 999,
                'effect' => 'ALTER_CONTRACT',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant_id', 'agency_id', 'reviewed_by', 'effect']);
        $this->assertSame(0, RentalUsageAnomalyReview::withoutGlobalScopes()->count());
        $this->assertSame($reviewAuditBefore, DB::table('audit_logs')
            ->where('action', 'prediction.rental_usage_anomaly.human_review_recorded')
            ->count());

        Carbon::setTestNow('2026-08-30 14:00:00+01');
        $this->actingAs($user)
            ->post(route('intelligence.rental-usage-anomalies.contract-reviews.store', $contract), [
                'decision' => 'follow_up',
                'note' => 'Premier constat factuel.',
            ])->assertRedirect(route('intelligence.rental-usage-anomalies.index', [
                'agency' => $fixture['agency']->id,
                'date_from' => '2026-08-29',
                'date_to' => '2026-08-29',
            ]));
        $firstReview = RentalUsageAnomalyReview::withoutGlobalScopes()->firstOrFail();
        $firstSnapshot = $firstReview->only(['id', 'decision', 'note', 'reviewed_by', 'tenant_id', 'agency_id', 'effect']);
        $firstReviewedAt = $firstReview->reviewed_at;
        $this->assertSame($user->id, $firstReview->reviewed_by);
        $this->assertSame($fixture['tenant']->id, $firstReview->tenant_id);
        $this->assertSame($fixture['agency']->id, $firstReview->agency_id);
        $this->assertSame(RentalUsageAnomalyContract::OPERATIONAL_EFFECT, $firstReview->effect);
        $this->assertTrue($firstReview->reviewed_at->equalTo(now()));

        Carbon::setTestNow('2026-08-30 14:01:00+01');
        $this->actingAs($user)
            ->post(route('intelligence.rental-usage-anomalies.contract-reviews.store', $contract), [
                'decision' => 'dismissed',
                'note' => 'Second constat indépendant.',
            ])->assertRedirect();
        $this->assertSame(2, RentalUsageAnomalyReview::withoutGlobalScopes()->count());
        $reloadedFirstReview = RentalUsageAnomalyReview::withoutGlobalScopes()->findOrFail($firstReview->id);
        $this->assertSame($firstSnapshot, $reloadedFirstReview->only(array_keys($firstSnapshot)));
        $this->assertTrue($reloadedFirstReview->reviewed_at->equalTo($firstReviewedAt));
        Carbon::setTestNow();

        RateLimiter::clear(
            'rental-usage-anomaly-review:tenant:'.$fixture['tenant']->id
            .'|actor:'.$user->id.'|contract:'.$secondContract->id,
        );
        RateLimiter::clear(
            'rental-usage-anomaly-review:tenant:'.$fixture['tenant']->id.'|actor:'.$user->id,
        );
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->actingAs($user)
                ->post(route('intelligence.rental-usage-anomalies.contract-reviews.store', $secondContract), [
                    'decision' => 'needs_information',
                    'note' => 'Vérification '.$attempt,
                ])->assertRedirect();
        }
        $this->actingAs($user)
            ->post(route('intelligence.rental-usage-anomalies.contract-reviews.store', $secondContract), [
                'decision' => 'needs_information',
            ])->assertTooManyRequests();

        $secondResult = RentalUsageAnomalyResult::withoutGlobalScopes()
            ->where('rental_usage_anomaly_run_id', $run->id)
            ->where('rental_contract_id', $secondContract->id)
            ->firstOrFail();
        $this->assertSame(10, RentalUsageAnomalyReview::withoutGlobalScopes()
            ->where('rental_usage_anomaly_result_id', $secondResult->id)
            ->count());
        $this->assertSame($businessBefore, $this->businessFingerprint());
        $this->assertSame(12, DB::table('audit_logs')
            ->where('action', 'prediction.rental_usage_anomaly.human_review_recorded')
            ->count() - $reviewAuditBefore);

        $route = app('router')->getRoutes()->getByName('intelligence.rental-usage-anomalies.contract-reviews.store');
        $this->assertNotNull($route);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant', $route->gatherMiddleware());
        $this->assertContains('password.changed', $route->gatherMiddleware());
        $this->assertContains('throttle:rental-usage-anomaly-review', $route->gatherMiddleware());
        $resolvedMiddleware = app('router')->gatherRouteMiddleware($route);
        $this->assertTrue(
            in_array(ValidateCsrfToken::class, $resolvedMiddleware, true)
                || in_array(VerifyCsrfToken::class, $resolvedMiddleware, true),
        );
    }

    public function test_role_matrix_is_preserved_for_queue_contract_review_and_launch(): void
    {
        $fixture = $this->fixture();
        $this->completeRun($fixture, 12.0);
        $contract = $fixture['contracts'][0];
        Queue::fake();

        $matrix = [
            'tenant-owner' => [true, true, true],
            'agency-manager' => [true, true, true],
            'fleet-manager' => [true, true, true],
            'viewer-auditor' => [true, false, false],
            'rental-agent' => [false, false, false],
            'accountant' => [false, false, false],
        ];

        foreach ($matrix as $role => [$canView, $canReview, $canLaunch]) {
            $user = $fixture['users'][$role];
            $queueResponse = $this->actingAs($user)
                ->get(route('intelligence.rental-usage-anomalies.index'));
            $canView ? $queueResponse->assertOk() : $queueResponse->assertForbidden();

            $contractResponse = $this->actingAs($user)->get(route('contracts.show', $contract));
            $contractResponse->assertOk();
            $canView
                ? $contractResponse->assertSee('Usage atypique à vérifier')
                : $contractResponse->assertDontSee('Usage atypique à vérifier');

            $reviewResponse = $this->actingAs($user)
                ->post(route('intelligence.rental-usage-anomalies.contract-reviews.store', $contract), [
                    'decision' => 'follow_up',
                ]);
            $canReview ? $reviewResponse->assertRedirect() : $reviewResponse->assertForbidden();

            $launchResponse = $this->actingAs($user)
                ->post(route('intelligence.rental-usage-anomalies.store', $fixture['export']));
            if ($canLaunch) {
                $launchResponse->assertRedirect()->assertSessionHasErrors('analysis');
            } else {
                $launchResponse->assertForbidden();
            }
        }

        $this->assertSame(3, RentalUsageAnomalyReview::withoutGlobalScopes()->count());
        $this->assertSame(0, RentalUsageAnomalyRun::withoutGlobalScopes()
            ->where('status', RentalUsageAnomalyRunStatus::Queued->value)
            ->count());
        Queue::assertNothingPushed();
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Entreprise revue usages atypiques',
            'settings' => ['timezone' => 'Africa/Casablanca'],
        ]);
        $agency = app(TenantContext::class)->run(
            $tenant,
            fn () => Agency::factory()->create(['name' => 'Agence revue usages atypiques']),
        );
        $users = [];
        foreach (['tenant-owner', 'agency-manager', 'fleet-manager', 'viewer-auditor', 'rental-agent', 'accountant'] as $role) {
            $users[$role] = $this->user(
                $tenant,
                $role === 'tenant-owner' ? null : $agency,
                $role,
            );
        }

        return app(TenantContext::class)->run($tenant, function () use ($tenant, $agency, $users): array {
            $category = VehicleCategory::create([
                'code' => 'ANOMALY-BUSINESS',
                'name' => 'Catégorie revue',
                'is_active' => true,
            ]);
            $customer = Customer::create([
                'agency_id' => $agency->id,
                'customer_type' => 'individual',
                'first_name' => 'Client',
                'last_name' => 'Revue',
                'email' => 'review@example.invalid',
                'verification_status' => 'verified',
            ]);
            $vehicle = Vehicle::create([
                'agency_id' => $agency->id,
                'vehicle_category_id' => $category->id,
                'registration_number' => 'REVIEW-001',
                'vin' => 'REVIEWVIN000000001',
                'brand' => 'Marque test',
                'model' => 'Modèle test',
                'production_year' => 2026,
                'fuel_type' => 'petrol',
                'transmission' => 'manual',
                'current_mileage' => 1000,
            ]);
            $contracts = [];
            for ($index = 1; $index <= 4; $index++) {
                $reservation = Reservation::create([
                    'agency_id' => $agency->id,
                    'customer_id' => $customer->id,
                    'vehicle_category_id' => $category->id,
                    'vehicle_id' => $vehicle->id,
                    'reservation_number' => 'RES-REVIEW-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                    'starts_at' => '2026-08-28 10:00:00+00',
                    'ends_at' => '2026-08-29 10:00:00+00',
                    'status' => 'converted',
                    'subtotal' => '0.00',
                    'options_total' => '0.00',
                    'total_amount' => '0.00',
                    'deposit_amount' => '0.00',
                    'currency' => 'MAD',
                    'pricing_snapshot' => [],
                    'created_by' => $users['agency-manager']->id,
                ]);
                $contracts[] = RentalContract::create([
                    'agency_id' => $agency->id,
                    'reservation_id' => $reservation->id,
                    'customer_id' => $customer->id,
                    'vehicle_id' => $vehicle->id,
                    'contract_number' => 'CTR-REVIEW-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                    'status' => RentalContractStatus::Returned,
                    'expected_start_at' => '2026-08-28 10:00:00+00',
                    'expected_return_at' => '2026-08-29 10:00:00+00',
                    'actual_start_at' => '2026-08-28 10:00:00+00',
                    'actual_return_at' => '2026-08-29 12:00:00+00',
                    'start_mileage' => 1000,
                    'return_mileage' => 1200,
                    'start_fuel_level' => '80.00',
                    'return_fuel_level' => '60.00',
                    'rental_subtotal' => '0.00',
                    'additional_charges_total' => '0.00',
                    'total_amount' => '0.00',
                    'deposit_required' => '0.00',
                    'currency' => 'MAD',
                    'returned_at' => '2026-08-29 12:00:00+00',
                    'created_by' => $users['agency-manager']->id,
                ]);
            }

            $exportUuid = (string) Str::uuid();
            $export = IntelligenceDatasetExportRun::create([
                'agency_id' => $agency->id,
                'run_id' => $exportUuid,
                'manifest_version' => '1.0.0',
                'schema_version' => '1.1',
                'dataset_version' => 'rentfleet-real-returns-v1.1.0',
                'scope_kind' => 'agency',
                'scope_key' => 'a_'.hash('sha256', 'agency-'.$agency->id),
                'date_from' => '2026-08-01',
                'date_to' => '2026-08-29',
                'timezone' => 'Africa/Casablanca',
                'row_count' => 200,
                'max_rows' => 10000,
                'content_sha256' => hash('sha256', 'business-review-fixture'),
                'byte_size' => 128,
                'format' => 'csv',
                'stored_path' => 'intelligence/dataset-exports/'.$exportUuid.'.csv',
                'original_name' => 'business-review.csv',
                'operational_effect' => RentalUsageAnomalyContract::OPERATIONAL_EFFECT,
                'created_by' => $users['agency-manager']->id,
                'created_at' => '2026-08-30 09:00:00+00',
            ]);

            return compact('tenant', 'agency', 'users', 'contracts', 'export');
        }, $agency->id);
    }

    private function user(Tenant $tenant, ?Agency $agency, string $role): User
    {
        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency?->id,
            'role_id' => Role::where('slug', $role)->value('id'),
            'must_change_password' => false,
        ]);
    }

    private function completeRun(
        array $fixture,
        float $lateHours,
        RentalUsageAnomalyRunStatus $terminalStatus = RentalUsageAnomalyRunStatus::Succeeded,
        ?CarbonImmutable $requestedAt = null,
    ): RentalUsageAnomalyRun {
        return app(TenantContext::class)->run($fixture['tenant'], function () use (
            $fixture,
            $lateHours,
            $terminalStatus,
            $requestedAt,
        ): RentalUsageAnomalyRun {
            $requestedAt ??= CarbonImmutable::parse('2026-08-30 10:00:00+00');
            $run = RentalUsageAnomalyRun::create([
                'agency_id' => $fixture['agency']->id,
                'run_id' => (string) Str::uuid(),
                'intelligence_dataset_export_run_id' => $fixture['export']->id,
                'requested_by' => $fixture['users']['agency-manager']->id,
                'status' => RentalUsageAnomalyRunStatus::Queued,
                'source_row_count' => 200,
                'minimum_rows' => RentalUsageAnomalyContract::MINIMUM_ROWS,
                'default_budget_basis_points' => RentalUsageAnomalyContract::DEFAULT_BUDGET_BASIS_POINTS,
                'primary_model' => RentalUsageAnomalyContract::PRIMARY_MODEL,
                'primary_version' => RentalUsageAnomalyContract::PRIMARY_VERSION,
                'challenger_model' => RentalUsageAnomalyContract::CHALLENGER_MODEL,
                'challenger_version' => RentalUsageAnomalyContract::CHALLENGER_VERSION,
                'random_state' => RentalUsageAnomalyContract::RANDOM_STATE,
                'runtime_sha256' => hash('sha256', 'runtime-fixture'),
                'compute' => 'CPU',
                'operational_effect' => RentalUsageAnomalyContract::OPERATIONAL_EFFECT,
                'requested_at' => $requestedAt,
            ]);
            $run->forceFill([
                'status' => RentalUsageAnomalyRunStatus::Running,
                'started_at' => $requestedAt->addSecond(),
            ])->save();

            foreach ($fixture['contracts'] as $offset => $contract) {
                $rank = $offset + 1;
                RentalUsageAnomalyResult::create([
                    'agency_id' => $fixture['agency']->id,
                    'rental_usage_anomaly_run_id' => $run->id,
                    'rental_contract_id' => $contract->id,
                    'row_id' => 'r_'.hash('sha256', $run->run_id.'|row|'.$contract->id),
                    'contract_key' => 'c_'.hash('sha256', $run->run_id.'|contract|'.$contract->id),
                    'event_at' => '2026-08-29 12:00:00+00',
                    'late_hours' => $lateHours + $offset,
                    'km_per_day' => 100 + $offset,
                    'fuel_drop_pct' => 20 + $offset,
                    'primary_score' => 9 - $offset,
                    'primary_rank' => $rank,
                    'primary_selected_005' => $rank === 1,
                    'primary_selected_010' => $rank <= 2,
                    'primary_selected_020' => true,
                    'primary_factors' => [
                        ['feature' => 'late_hours', 'value' => $lateHours + $offset, 'median' => 2, 'mad' => 1, 'positive_robust_deviation' => 4],
                        ['feature' => 'km_per_day', 'value' => 100 + $offset, 'median' => 50, 'mad' => 10, 'positive_robust_deviation' => 3],
                    ],
                    'challenger_score' => 1 - ($offset / 10),
                    'challenger_rank' => $rank,
                    'challenger_selected_005' => $rank === 1,
                    'challenger_selected_010' => $rank <= 2,
                    'challenger_selected_020' => true,
                    'operational_effect' => RentalUsageAnomalyContract::OPERATIONAL_EFFECT,
                    'recorded_at' => $requestedAt->addSeconds(2),
                ]);
            }

            if ($terminalStatus === RentalUsageAnomalyRunStatus::Succeeded) {
                $run->forceFill([
                    'status' => $terminalStatus,
                    'data_status' => 'usable',
                    'budget_results' => [
                        ['basis_points' => 50, 'selected_count' => 1],
                        ['basis_points' => 100, 'selected_count' => 2],
                        ['basis_points' => 200, 'selected_count' => 4],
                    ],
                    'candidate_count' => 4,
                    'finished_at' => $requestedAt->addSeconds(3),
                ])->save();
            } else {
                $run->forceFill([
                    'status' => RentalUsageAnomalyRunStatus::Failed,
                    'failure_code' => 'ANOMALY_PROCESS_FAILED',
                    'finished_at' => $requestedAt->addSeconds(3),
                ])->save();
            }

            return $run->fresh();
        }, $fixture['agency']->id);
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
            'reservations' => DB::table('reservations')->count(),
            'vehicles' => DB::table('vehicles')->count(),
            'charges' => DB::table('contract_charges')->count(),
            'invoices' => DB::table('invoices')->count(),
            'payments' => DB::table('payments')->count(),
        ];
    }
}
