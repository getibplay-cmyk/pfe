<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\IntelligenceDatasetExportRun;
use App\Models\IntelligenceResultBatch;
use App\Models\IntelligenceResultBatchDecision;
use App\Models\IntelligenceResultRow;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\J14\J14CanonicalPayload;
use App\Support\Intelligence\PredictionInput;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class J14ResultBatchImportReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-13 12:00:00+00:00');
        Storage::fake('local');
        $this->seed(RolesPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_closed_batch_is_imported_downloaded_and_human_reviewed_without_operational_effect(): void
    {
        $fixture = $this->fixture();
        [$run, $rowKeys] = $this->exportRun($fixture, 2);
        $payload = $this->payload($run, $rowKeys);

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.result-batches.store', $run), [
                'result_batch' => $this->jsonFile($payload),
            ])
            ->assertRedirect(route('intelligence.result-batches.index'))
            ->assertSessionHas('status', 'Lot J14-B validé et importé sans effet opérationnel.');

        $batch = IntelligenceResultBatch::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($payload['batch_id'], $batch->batch_id);
        $this->assertSame($run->id, $batch->intelligence_dataset_export_run_id);
        $this->assertSame('not_run_synthetic_contract_fixture', $batch->computation_status);
        $this->assertSame('NO_OPERATIONAL_ACTION', $batch->operational_effect);
        $this->assertSame('pending', $batch->reviewStatus());
        $this->assertSame(2, $batch->result_count);

        $rows = IntelligenceResultRow::withoutGlobalScopes()->orderBy('row_position')->get();
        $this->assertSame([0, 1], $rows->pluck('row_position')->all());
        $this->assertSame($rowKeys, $rows->pluck('row_key')->all());
        $this->assertSame(['low', 'medium'], $rows->pluck('priority')->all());
        Storage::disk('local')->assertExists($batch->stored_path);
        $canonical = Storage::disk('local')->get($batch->stored_path);
        $this->assertSame($batch->byte_size, strlen($canonical));
        $this->assertSame($batch->content_sha256, hash('sha256', $canonical));
        $this->assertEquals($payload, json_decode($canonical, true, flags: JSON_THROW_ON_ERROR));

        $audit = AuditLog::withoutGlobalScopes()
            ->where('action', 'prediction.result_batch.imported')
            ->firstOrFail();
        $this->assertEqualsCanonicalizing([
            'batch_id',
            'run_id',
            'schema_version',
            'result_count',
            'source_kind',
            'computation_status',
            'outcome',
            'effect',
        ], array_keys($audit->new_values));
        foreach (['stored_path', 'idempotency_key', 'content_sha256', 'row_id', 'late_hours'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $audit->new_values);
        }

        $this->actingAs($fixture['user'])
            ->get(route('intelligence.result-batches.index'))
            ->assertOk()
            ->assertSee('J14-B · import et revue des lots de résultats')
            ->assertSee($batch->batch_id)
            ->assertSee('Aucune preuve acceptée et intègre disponible')
            ->assertDontSee($batch->stored_path)
            ->assertDontSee($batch->content_sha256);

        $download = $this->actingAs($fixture['user'])
            ->get(route('intelligence.result-batches.download', $batch))
            ->assertOk()
            ->assertHeader('content-type', 'application/json; charset=UTF-8')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-rentfleet-result-batch', $batch->batch_id);
        $this->assertSame($canonical, $download->streamedContent());

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.result-batches.decisions.store', $batch), [
                'decision' => 'accepted_for_demo_review',
                'reason_code' => 'DEMO_REJECTED',
            ])
            ->assertSessionHasErrors('reason_code');
        $this->assertSame(0, IntelligenceResultBatchDecision::withoutGlobalScopes()->count());

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.result-batches.decisions.store', $batch), [
                'decision' => 'accepted_for_demo_review',
                'reason_code' => 'SYNTHETIC_CONTRACT_REVIEW_ACCEPTED',
            ])
            ->assertRedirect(route('intelligence.result-batches.index'));

        $decision = IntelligenceResultBatchDecision::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('accepted_for_demo_review', $decision->decision->value);
        $this->assertSame('NO_OPERATIONAL_ACTION', $decision->effect);
        $this->actingAs($fixture['user'])
            ->get(route('intelligence.result-batches.index'))
            ->assertOk()
            ->assertSee('Dernière preuve acceptée disponible');

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.result-batches.decisions.store', $batch), [
                'decision' => 'rejected',
                'reason_code' => 'DEMO_REJECTED',
            ])
            ->assertStatus(409);

        $this->assertSame(1, IntelligenceResultBatchDecision::withoutGlobalScopes()->count());
        foreach (['vehicles', 'reservations', 'rental_contracts', 'maintenance_orders', 'invoices', 'payments'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_same_key_same_payload_replays_and_same_key_different_payload_conflicts(): void
    {
        $fixture = $this->fixture();
        [$run, $rowKeys] = $this->exportRun($fixture, 2);
        $payload = $this->payload($run, $rowKeys);

        foreach ([1, 2] as $attempt) {
            $this->actingAs($fixture['user'])
                ->post(route('intelligence.result-batches.store', $run), [
                    'result_batch' => $this->jsonFile($payload, 'batch-'.$attempt.'.json'),
                ])
                ->assertRedirect(route('intelligence.result-batches.index'));
        }

        $this->assertSame(1, IntelligenceResultBatch::withoutGlobalScopes()->count());
        $this->assertSame(2, IntelligenceResultRow::withoutGlobalScopes()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('intelligence/result-batches'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'prediction.result_batch.replayed']);

        $conflict = $payload;
        $conflict['batch_id'] = (string) Str::uuid();
        $conflict['results'][0]['factors'][0]['level'] = 'elevated';
        $conflict['results'][0]['priority'] = 'medium';
        $conflict = $this->withDigest($conflict);

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.result-batches.store', $run), [
                'result_batch' => $this->jsonFile($conflict, 'conflict.json'),
            ])
            ->assertStatus(409);

        $this->assertSame(1, IntelligenceResultBatch::withoutGlobalScopes()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('intelligence/result-batches'));
    }

    public function test_unknown_fields_wrong_lineage_quantitative_data_and_tampered_snapshot_fail_closed(): void
    {
        $fixture = $this->fixture();
        [$run, $rowKeys] = $this->exportRun($fixture, 2);
        $base = $this->payload($run, $rowKeys);

        $unknown = $base;
        $unknown['model_score'] = '0.99';
        $this->assertInvalidBatch($fixture['user'], $run, $unknown);

        $wrongCount = $base;
        $wrongCount['export']['row_count'] = 1;
        $this->assertInvalidBatch($fixture['user'], $run, $this->withDigest($wrongCount));

        $wrongOrder = $base;
        $wrongOrder['results'] = array_reverse($wrongOrder['results']);
        $this->assertInvalidBatch($fixture['user'], $run, $this->withDigest($wrongOrder));

        $quantitative = $base;
        $quantitative['results'][0]['factors'][0]['score'] = 0.99;
        $this->assertInvalidBatch($fixture['user'], $run, $this->withDigest($quantitative));

        $wrongPriority = $base;
        $wrongPriority['results'][0]['priority'] = 'high';
        $this->assertInvalidBatch($fixture['user'], $run, $this->withDigest($wrongPriority));

        $unsafe = $base;
        $unsafe['safety']['automatic_action_allowed'] = true;
        $this->assertInvalidBatch($fixture['user'], $run, $this->withDigest($unsafe));

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.result-batches.store', $run), [
                'result_batch' => UploadedFile::fake()->createWithContent('payload.php', $this->encodeJson($base)),
            ])
            ->assertSessionHasErrors('result_batch');

        Storage::disk('local')->put($run->stored_path, 'snapshot-altéré');
        $this->assertInvalidBatch($fixture['user'], $run, $base);

        $this->assertSame(0, IntelligenceResultBatch::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('intelligence/result-batches'));
    }

    public function test_result_import_and_review_follow_tenant_agency_and_role_boundaries(): void
    {
        $fixture = $this->fixture();
        [$run, $rowKeys] = $this->exportRun($fixture, 1);
        $payload = $this->payload($run, $rowKeys);
        $fleetManager = $this->user($fixture, 'fleet-manager', $fixture['agency']);
        $agencyManager = $this->user($fixture, 'agency-manager', $fixture['agency']);
        $viewer = $this->user($fixture, 'viewer-auditor', $fixture['agency']);
        $otherAgency = app(TenantContext::class)->run($fixture['tenant'], fn () => Agency::factory()->create());
        $otherFleetManager = $this->user($fixture, 'fleet-manager', $otherAgency);
        $foreign = $this->fixture();

        $this->actingAs($agencyManager)
            ->post(route('intelligence.result-batches.store', $run), [
                'result_batch' => $this->jsonFile($payload),
            ])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->post(route('intelligence.result-batches.store', $run), [
                'result_batch' => $this->jsonFile($payload),
            ])
            ->assertForbidden();
        $this->actingAs($otherFleetManager)
            ->post(route('intelligence.result-batches.store', $run), [
                'result_batch' => $this->jsonFile($payload),
            ])
            ->assertForbidden();
        $this->actingAs($foreign['user'])
            ->post(route('intelligence.result-batches.store', $run), [
                'result_batch' => $this->jsonFile($payload),
            ])
            ->assertNotFound();

        $this->actingAs($fleetManager)
            ->post(route('intelligence.result-batches.store', $run), [
                'result_batch' => $this->jsonFile($payload),
                'tenant_id' => $fixture['tenant']->id,
            ])
            ->assertSessionHasErrors('tenant_id');
        $this->actingAs($fleetManager)
            ->post(route('intelligence.result-batches.store', $run), [
                'result_batch' => $this->jsonFile($payload),
            ])
            ->assertRedirect(route('intelligence.result-batches.index'));

        $batch = IntelligenceResultBatch::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($viewer)
            ->get(route('intelligence.result-batches.index'))
            ->assertOk()
            ->assertSee($batch->batch_id);
        $this->actingAs($viewer)
            ->get(route('intelligence.result-batches.download', $batch))
            ->assertOk()
            ->streamedContent();
        $this->actingAs($otherFleetManager)
            ->post(route('intelligence.result-batches.decisions.store', $batch), [
                'decision' => 'rejected',
                'reason_code' => 'DEMO_REJECTED',
            ])
            ->assertForbidden();
        $this->actingAs($foreign['user'])
            ->get(route('intelligence.result-batches.download', $batch))
            ->assertNotFound();
    }

    public function test_private_artifact_integrity_controls_download_and_fallback(): void
    {
        $fixture = $this->fixture();
        [$run, $rowKeys] = $this->exportRun($fixture, 1);
        $payload = $this->payload($run, $rowKeys);
        $this->actingAs($fixture['user'])->post(route('intelligence.result-batches.store', $run), [
            'result_batch' => $this->jsonFile($payload),
        ])->assertRedirect();
        $batch = IntelligenceResultBatch::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($fixture['user'])->post(route('intelligence.result-batches.decisions.store', $batch), [
            'decision' => 'accepted_for_demo_review',
            'reason_code' => 'HUMAN_REVIEW_DEMO_ONLY',
        ])->assertRedirect();

        Storage::disk('local')->put($batch->stored_path, 'lot-altéré');
        $downloadsBefore = AuditLog::withoutGlobalScopes()
            ->where('action', 'prediction.result_batch.downloaded')
            ->count();
        $this->actingAs($fixture['user'])
            ->get(route('intelligence.result-batches.download', $batch))
            ->assertStatus(409);
        $this->actingAs($fixture['user'])
            ->get(route('intelligence.result-batches.index'))
            ->assertOk()
            ->assertSee('Aucune preuve acceptée et intègre disponible');
        $this->assertSame($downloadsBefore, AuditLog::withoutGlobalScopes()
            ->where('action', 'prediction.result_batch.downloaded')
            ->count());

        Storage::disk('local')->delete($batch->stored_path);
        $this->actingAs($fixture['user'])
            ->get(route('intelligence.result-batches.download', $batch))
            ->assertStatus(410);
    }

    public function test_failed_database_transaction_removes_the_private_canonical_artifact(): void
    {
        $fixture = $this->fixture();
        [$run, $rowKeys] = $this->exportRun($fixture, 1);
        $this->mock(AuditRecorder::class, function ($mock): void {
            $mock->shouldReceive('record')
                ->once()
                ->andThrow(new RuntimeException('Échec d’audit synthétique attendu.'));
        });
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($fixture['user'])->post(route('intelligence.result-batches.store', $run), [
                'result_batch' => $this->jsonFile($this->payload($run, $rowKeys)),
            ]);
            $this->fail('La transaction devait être annulée après l’échec d’audit.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Échec d’audit synthétique attendu.', $exception->getMessage());
        }

        $this->assertSame(0, IntelligenceResultBatch::withoutGlobalScopes()->count());
        $this->assertSame(0, IntelligenceResultRow::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('intelligence/result-batches'));
    }

    public function test_postgresql_guards_routes_configuration_and_rbac_contract_are_explicit(): void
    {
        $fixture = $this->fixture();
        [$run, $rowKeys] = $this->exportRun($fixture, 1);
        $this->actingAs($fixture['user'])->post(route('intelligence.result-batches.store', $run), [
            'result_batch' => $this->jsonFile($this->payload($run, $rowKeys)),
        ])->assertRedirect();
        $batch = IntelligenceResultBatch::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('rentfleet_test', DB::connection()->getDatabaseName());
        $this->assertSame(77, DB::table('migrations')->count());
        foreach (['intelligence_result_batches', 'intelligence_result_rows', 'intelligence_result_batch_decisions'] as $table) {
            $this->assertTrue(DB::table('information_schema.tables')
                ->where('table_schema', 'public')
                ->where('table_name', $table)
                ->exists(), $table);
        }
        foreach ([
            'intelligence_result_batches_append_only',
            'intelligence_result_rows_append_only',
            'intelligence_result_decisions_append_only',
            'intelligence_result_batches_completeness_guard',
        ] as $trigger) {
            $this->assertTrue(DB::table('information_schema.triggers')
                ->where('trigger_schema', 'public')
                ->where('trigger_name', $trigger)
                ->exists(), $trigger);
        }

        $this->assertPostgreSqlConstraint(fn () => DB::table('intelligence_result_batches')
            ->where('id', $batch->id)
            ->update(['result_count' => 999]));
        $this->assertPostgreSqlConstraint(fn () => DB::table('intelligence_result_rows')
            ->where('intelligence_result_batch_id', $batch->id)
            ->update(['priority' => 'high']));
        $this->assertPostgreSqlConstraint(fn () => DB::table('intelligence_result_batches')
            ->where('id', $batch->id)
            ->delete());

        foreach ([
            'intelligence.result-batches.index',
            'intelligence.result-batches.store',
            'intelligence.result-batches.download',
            'intelligence.result-batches.decisions.store',
        ] as $route) {
            $this->assertTrue(app('router')->has($route), $route);
        }
        $this->assertTrue((bool) config('intelligence.result_batches.synthetic_only'));
        $this->assertFalse((bool) config('intelligence.result_batches.automatic_actions_allowed'));
        $this->assertFalse((bool) config('intelligence.result_batches.ready_for_saas'));
        $this->assertFalse((bool) config('intelligence.result_batches.production_allowed'));

        $expected = [
            'tenant-owner' => true,
            'agency-manager' => false,
            'rental-agent' => false,
            'fleet-manager' => true,
            'accountant' => false,
            'viewer-auditor' => false,
        ];
        foreach ($expected as $slug => $canReview) {
            $permissions = Role::where('slug', $slug)->firstOrFail()->permissions->pluck('slug');
            $this->assertSame($canReview, $permissions->contains('prediction.demo.review'), $slug);
        }
    }

    /** @return array{tenant: Tenant, agency: Agency, user: User} */
    private function fixture(string $roleSlug = 'tenant-owner'): array
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Entreprise fictive J14-B',
            'settings' => ['timezone' => 'Africa/Casablanca'],
        ]);
        $agency = app(TenantContext::class)->run(
            $tenant,
            fn () => Agency::factory()->create(['name' => 'Agence fictive J14-B']),
        );
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => $roleSlug === 'tenant-owner' ? null : $agency->id,
            'role_id' => Role::where('slug', $roleSlug)->value('id'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        return compact('tenant', 'agency', 'user');
    }

    private function user(array $fixture, string $roleSlug, ?Agency $agency): User
    {
        return User::factory()->create([
            'tenant_id' => $fixture['tenant']->id,
            'agency_id' => $agency?->id,
            'role_id' => Role::where('slug', $roleSlug)->value('id'),
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    /** @return array{IntelligenceDatasetExportRun, list<string>} */
    private function exportRun(array $fixture, int $rowCount): array
    {
        $rowKeys = [];
        $stream = fopen('php://temp', 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, PredictionInput::headers(), ';', '"', '', "\n");

        for ($position = 0; $position < $rowCount; $position++) {
            $rowKey = 'r_'.hash('sha256', 'j14-b-row-'.$position);
            $rowKeys[] = $rowKey;
            fputcsv($stream, [
                PredictionInput::SCHEMA_VERSION,
                PredictionInput::DATASET_VERSION,
                $rowKey,
                't_'.hash('sha256', 'tenant'),
                'a_'.hash('sha256', 'agency'),
                'c_'.hash('sha256', 'contract-'.$position),
                '2026-08-12T10:00:00Z',
                '0.000000',
                '100.000000',
                '0.000000',
            ], ';', '"', '', "\n");
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        $this->assertIsString($content);

        $runId = (string) Str::uuid();
        $storedPath = 'intelligence/dataset-exports/'.$runId.'.csv';
        Storage::disk('local')->put($storedPath, $content);

        $run = app(TenantContext::class)->run(
            $fixture['tenant'],
            fn () => DB::transaction(fn () => IntelligenceDatasetExportRun::create([
                'agency_id' => $fixture['agency']->id,
                'run_id' => $runId,
                'manifest_version' => '1.0.0',
                'schema_version' => PredictionInput::SCHEMA_VERSION,
                'dataset_version' => PredictionInput::DATASET_VERSION,
                'scope_kind' => 'agency',
                'scope_key' => 'a_'.hash('sha256', 'scope-'.$runId),
                'date_from' => '2026-08-01',
                'date_to' => '2026-08-12',
                'timezone' => 'Africa/Casablanca',
                'row_count' => $rowCount,
                'max_rows' => 10000,
                'content_sha256' => hash('sha256', $content),
                'byte_size' => strlen($content),
                'format' => 'csv',
                'stored_path' => $storedPath,
                'original_name' => 'rentfleet_intelligence_returns_2026-08-01_2026-08-12.csv',
                'operational_effect' => 'NO_OPERATIONAL_ACTION',
                'created_by' => $fixture['user']->id,
                'created_at' => now()->subHour(),
            ])),
            $fixture['agency']->id,
        );

        return [$run, $rowKeys];
    }

    /** @param list<string> $rowKeys @return array<string, mixed> */
    private function payload(IntelligenceDatasetExportRun $run, array $rowKeys): array
    {
        $payload = [
            'schema_version' => '1.0.0',
            'batch_id' => (string) Str::uuid(),
            'generated_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'source' => [
                'kind' => 'synthetic_fixture',
                'computation_status' => 'not_run_synthetic_contract_fixture',
                'producer_name' => 'rentfleet-j14-synthetic-fixture',
                'producer_version' => '1.0.0',
                'environment' => 'offline_contract_demo',
            ],
            'export' => [
                'run_id' => $run->run_id,
                'schema_version' => $run->schema_version,
                'dataset_version' => $run->dataset_version,
                'row_count' => $run->row_count,
                'content_sha256' => $run->content_sha256,
            ],
            'results' => array_map(fn (string $rowKey, int $position): array => [
                'row_id' => $rowKey,
                'advisory_kind' => 'rental_usage_review',
                'priority' => $position === 0 ? 'low' : 'medium',
                'summary_code' => 'SYNTHETIC_REVIEW_ONLY',
                'factors' => [
                    ['name' => 'late_hours', 'level' => $position === 0 ? 'normal' : 'elevated'],
                    ['name' => 'km_per_day', 'level' => 'normal'],
                    ['name' => 'fuel_drop_pct', 'level' => 'normal'],
                ],
                'operational_effect' => 'NO_OPERATIONAL_ACTION',
            ], $rowKeys, array_keys($rowKeys)),
            'human_review' => [
                'required' => true,
                'initial_status' => 'pending',
                'effect' => 'NO_OPERATIONAL_ACTION',
            ],
            'safety' => [
                'synthetic_only' => true,
                'contains_real_customer_data' => false,
                'contains_direct_identifiers' => false,
                'contains_coordinates' => false,
                'automatic_action_allowed' => false,
                'ready_for_saas' => false,
                'production_allowed' => false,
            ],
            'idempotency' => [
                'key' => (string) Str::uuid(),
                'policy' => 'SAME_KEY_SAME_PAYLOAD_ONLY',
                'canonical_payload_sha256' => str_repeat('0', 64),
            ],
        ];

        return $this->withDigest($payload);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function withDigest(array $payload): array
    {
        $payload['idempotency']['canonical_payload_sha256'] = app(J14CanonicalPayload::class)->digest($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function jsonFile(array $payload, string $name = 'j14-result-batch.json'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->encodeJson($payload));
    }

    /** @param array<string, mixed> $payload */
    private function encodeJson(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $payload */
    private function assertInvalidBatch(User $actor, IntelligenceDatasetExportRun $run, array $payload): void
    {
        $this->actingAs($actor)
            ->post(route('intelligence.result-batches.store', $run), [
                'result_batch' => $this->jsonFile($payload),
            ])
            ->assertSessionHasErrors('result_batch');
    }

    private function assertPostgreSqlConstraint(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Une contrainte PostgreSQL devait refuser cette mutation.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', (string) $exception->getCode());
        }
    }
}
