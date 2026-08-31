<?php

namespace App\Actions\Intelligence;

use App\Enums\IntelligenceCapability;
use App\Enums\RentalUsageAnomalyRunStatus;
use App\Exceptions\RentalUsageAnomalyExecutionException;
use App\Models\RentalContract;
use App\Models\RentalUsageAnomalyResult;
use App\Models\RentalUsageAnomalyRun;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\RentalUsageAnomaly\RentalUsageAnomalyContract;
use App\Support\Intelligence\RentalUsageAnomaly\RentalUsageAnomalyOutputValidator;
use App\Support\Intelligence\RentalUsageAnomaly\RentalUsageAnomalySnapshotInspector;
use App\Support\Intelligence\RentalUsageAnomaly\ResolveRentalUsageAnomalyContracts;
use App\Support\Intelligence\RentalUsageAnomaly\ValidatedRentalUsageAnomalyOutput;
use App\Support\Intelligence\TenantIntelligenceAccess;
use App\Support\Tenancy\TenantContext;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ExecuteRentalUsageAnomalyRun
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantIntelligenceAccess $tenantAccess,
        private readonly RentalUsageAnomalySnapshotInspector $snapshotInspector,
        private readonly RentalUsageAnomalyOutputValidator $outputValidator,
        private readonly ResolveRentalUsageAnomalyContracts $contractResolver,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(string $runId, int $tenantId, int $actorId): void
    {
        $this->context->run($tenantId, function () use ($runId, $tenantId, $actorId): void {
            if (! $this->tenantAccess->usable(IntelligenceCapability::RentalUsageAnomaly, $tenantId)) {
                throw new RentalUsageAnomalyExecutionException('TENANT_INTELLIGENCE_UNAVAILABLE');
            }
            $run = $this->markRunning($runId, $actorId);
            $actor = User::query()
                ->whereKey($actorId)
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->first();
            if ($actor === null
                || ! $actor->hasPermission('prediction.view')
                || ! $actor->hasPermission('prediction.anomaly.review')
                || ($actor->agency_id !== null && $actor->agency_id !== $run->agency_id)) {
                throw new RentalUsageAnomalyExecutionException('RUN_ACTOR_NOT_AUTHORIZED');
            }

            try {
                $snapshot = $this->snapshotInspector->inspect($run->exportRun);
            } catch (RentalUsageAnomalyExecutionException) {
                throw new RentalUsageAnomalyExecutionException('SOURCE_SNAPSHOT_INVALID');
            }
            $output = $this->executeProcess($run);
            $validated = $this->outputValidator->validate($output, $run, $snapshot);
            $contracts = $this->contractResolver->resolve($run->exportRun, $validated->rows);
            $this->persist($run, $validated, $contracts);
        });
    }

    private function markRunning(string $runId, int $actorId): RentalUsageAnomalyRun
    {
        return DB::transaction(function () use ($runId, $actorId): RentalUsageAnomalyRun {
            $run = RentalUsageAnomalyRun::query()
                ->with('exportRun')
                ->where('run_id', $runId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($run->requested_by !== $actorId || $run->status !== RentalUsageAnomalyRunStatus::Queued) {
                throw new RentalUsageAnomalyExecutionException('RUN_STATE_CONFLICT');
            }
            $run->forceFill([
                'status' => RentalUsageAnomalyRunStatus::Running,
                'started_at' => now(),
            ])->save();

            return $run;
        }, 3);
    }

    private function executeProcess(RentalUsageAnomalyRun $run): string
    {
        $binary = (string) config('intelligence.rental_usage_anomaly.python_binary');
        $script = (string) config('intelligence.rental_usage_anomaly.runtime_script');
        $timeout = (int) config('intelligence.rental_usage_anomaly.runtime_timeout_seconds');
        if ($binary === '' || ! is_file($script) || $timeout < 10 || $timeout > 120) {
            throw new RentalUsageAnomalyExecutionException('RUNTIME_CONFIGURATION_INVALID');
        }

        try {
            $snapshotPath = Storage::disk((string) config('intelligence.dataset_exports.disk'))
                ->path($run->exportRun->stored_path);
            $result = Process::path(sys_get_temp_dir())
                ->timeout($timeout)
                ->env($this->closedEnvironment())
                ->run([
                    $binary,
                    $script,
                    '--run-id',
                    $run->run_id,
                    '--snapshot',
                    $snapshotPath,
                    '--snapshot-sha256',
                    $run->exportRun->content_sha256,
                    '--snapshot-bytes',
                    (string) $run->exportRun->byte_size,
                    '--snapshot-rows',
                    (string) $run->source_row_count,
                    '--minimum-rows',
                    (string) $run->minimum_rows,
                    '--runtime-sha256',
                    $run->runtime_sha256,
                    '--stdout',
                ]);
        } catch (ProcessTimedOutException) {
            throw new RentalUsageAnomalyExecutionException('ANOMALY_PROCESS_TIMEOUT');
        } catch (RentalUsageAnomalyExecutionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RentalUsageAnomalyExecutionException('ANOMALY_PROCESS_START_FAILED');
        }
        if ($result->failed()) {
            throw new RentalUsageAnomalyExecutionException('ANOMALY_PROCESS_FAILED');
        }
        $output = $result->output();
        if ($output === '' || strlen($output) > 4_194_304) {
            throw new RentalUsageAnomalyExecutionException('ANOMALY_OUTPUT_INVALID');
        }

        return $output;
    }

    /** @param array<string, RentalContract> $contracts */
    private function persist(
        RentalUsageAnomalyRun $run,
        ValidatedRentalUsageAnomalyOutput $validated,
        array $contracts,
    ): void {
        DB::transaction(function () use ($run, $validated, $contracts): void {
            $locked = RentalUsageAnomalyRun::query()
                ->where('run_id', $run->run_id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($locked->status !== RentalUsageAnomalyRunStatus::Running) {
                throw new RentalUsageAnomalyExecutionException('RUN_STATE_CONFLICT');
            }
            foreach ($validated->rows as $row) {
                $contract = $contracts[$row['contract_key']];
                RentalUsageAnomalyResult::create([
                    'agency_id' => $contract->agency_id,
                    'rental_usage_anomaly_run_id' => $locked->id,
                    'rental_contract_id' => $contract->id,
                    'row_id' => $row['row_id'],
                    'contract_key' => $row['contract_key'],
                    'event_at' => $row['event_at'],
                    'late_hours' => $row['features']['late_hours'],
                    'km_per_day' => $row['features']['km_per_day'],
                    'fuel_drop_pct' => $row['features']['fuel_drop_pct'],
                    'primary_score' => $row['primary_score'],
                    'primary_rank' => $row['primary_rank'],
                    'primary_selected_005' => in_array(50, $row['primary_budgets'], true),
                    'primary_selected_010' => in_array(100, $row['primary_budgets'], true),
                    'primary_selected_020' => in_array(200, $row['primary_budgets'], true),
                    'primary_factors' => $row['primary_factors'],
                    'challenger_score' => $row['challenger_score'],
                    'challenger_rank' => $row['challenger_rank'],
                    'challenger_selected_005' => in_array(50, $row['challenger_budgets'], true),
                    'challenger_selected_010' => in_array(100, $row['challenger_budgets'], true),
                    'challenger_selected_020' => in_array(200, $row['challenger_budgets'], true),
                    'operational_effect' => RentalUsageAnomalyContract::OPERATIONAL_EFFECT,
                    'recorded_at' => now(),
                ]);
            }

            $locked->forceFill([
                'status' => RentalUsageAnomalyRunStatus::Succeeded,
                'data_status' => $validated->dataStatus,
                'budget_results' => $validated->budgets,
                'candidate_count' => count($validated->rows),
                'finished_at' => now(),
            ])->save();

            $defaultBudget = collect($validated->budgets)->firstWhere(
                'basis_points',
                RentalUsageAnomalyContract::DEFAULT_BUDGET_BASIS_POINTS,
            );
            $this->audit->record('prediction.rental_usage_anomaly.run_succeeded', $locked, [], [
                'run_id' => $locked->run_id,
                'data_status' => $validated->dataStatus,
                'source_row_count' => $locked->source_row_count,
                'candidate_count' => count($validated->rows),
                'default_budget_basis_points' => RentalUsageAnomalyContract::DEFAULT_BUDGET_BASIS_POINTS,
                'default_selected_count' => $defaultBudget['selected_count'] ?? 0,
                'challenger_jaccard' => $defaultBudget['jaccard'] ?? null,
                'human_review_required' => true,
                'effect' => RentalUsageAnomalyContract::OPERATIONAL_EFFECT,
            ]);
        }, 3);
    }

    /** @return array<string, string|false> */
    private function closedEnvironment(): array
    {
        return [
            'PYTHONDONTWRITEBYTECODE' => '1',
            'PYTHONHASHSEED' => (string) RentalUsageAnomalyContract::RANDOM_STATE,
            'OMP_NUM_THREADS' => '1',
            'OPENBLAS_NUM_THREADS' => '1',
            'MKL_NUM_THREADS' => '1',
            'APP_KEY' => false,
            'DATABASE_URL' => false,
            'DB_URL' => false,
            'DB_USERNAME' => false,
            'DB_PASSWORD' => false,
            'REDIS_PASSWORD' => false,
            'MAIL_USERNAME' => false,
            'MAIL_PASSWORD' => false,
            'AWS_ACCESS_KEY_ID' => false,
            'AWS_SECRET_ACCESS_KEY' => false,
            'AWS_SESSION_TOKEN' => false,
            'INTELLIGENCE_EXPORT_HMAC_KEY' => false,
            'DEMO_PASSWORD' => false,
            'OPENAI_API_KEY' => false,
            'STRIPE_SECRET' => false,
            'PGPASSWORD' => false,
        ];
    }
}
