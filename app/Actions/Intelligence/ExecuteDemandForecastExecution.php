<?php

namespace App\Actions\Intelligence;

use App\Enums\DemandForecastExecutionStatus;
use App\Enums\IntelligenceCapability;
use App\Exceptions\DemandForecastExecutionException;
use App\Exceptions\DemandForecastValidationException;
use App\Models\DemandForecastExecutionRun;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\DemandForecasting\DemandForecastArtifactVerifier;
use App\Support\Intelligence\DemandForecasting\DemandForecastContract;
use App\Support\Intelligence\DemandForecasting\DemandForecastModelArtifact;
use App\Support\Intelligence\TenantIntelligenceAccess;
use App\Support\Tenancy\TenantContext;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Throwable;

final class ExecuteDemandForecastExecution
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantIntelligenceAccess $tenantAccess,
        private readonly DemandForecastModelArtifact $modelArtifact,
        private readonly DemandForecastArtifactVerifier $historyArtifact,
        private readonly ImportDemandForecastBatch $import,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(string $runId, int $tenantId, int $actorId): void
    {
        $this->context->run($tenantId, function () use ($runId, $tenantId, $actorId): void {
            if (! $this->tenantAccess->usable(IntelligenceCapability::DemandForecast, $tenantId)) {
                throw new DemandForecastExecutionException('TENANT_INTELLIGENCE_UNAVAILABLE');
            }
            $run = $this->markRunning($runId, $actorId);
            $history = $run->historyExport;
            $actor = User::query()
                ->whereKey($actorId)
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->first();
            if ($actor === null
                || ! $actor->hasPermission('prediction.forecast.import')
                || ($actor->agency_id !== null && $actor->agency_id !== $history->agency_id)) {
                throw new DemandForecastExecutionException('RUN_ACTOR_NOT_AUTHORIZED');
            }
            if (! $this->modelArtifact->configuredIsValid()) {
                throw new DemandForecastExecutionException('MODEL_ARTIFACT_INVALID');
            }
            if (! $this->historyArtifact->validHistory($history)) {
                throw new DemandForecastExecutionException('HISTORY_ARTIFACT_INVALID');
            }

            $output = $this->executeProcess($run);
            try {
                $forecast = $this->import->handlePayload($history, $output, $actor)->run;
            } catch (DemandForecastValidationException) {
                throw new DemandForecastExecutionException('HGB_OUTPUT_CONTRACT_INVALID');
            } catch (Throwable) {
                throw new DemandForecastExecutionException('HGB_OUTPUT_IMPORT_FAILED');
            }

            $locked = DB::transaction(function () use ($run, $forecast): DemandForecastExecutionRun {
                $candidate = DemandForecastExecutionRun::query()
                    ->where('run_id', $run->run_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($candidate->status !== DemandForecastExecutionStatus::Running) {
                    throw new DemandForecastExecutionException('RUN_STATE_CONFLICT');
                }
                $candidate->forceFill([
                    'demand_forecast_run_id' => $forecast->id,
                    'status' => DemandForecastExecutionStatus::Succeeded,
                    'finished_at' => now(),
                ])->save();

                return $candidate;
            }, 3);

            $this->audit->record('prediction.demand_forecast.execution_succeeded', $locked, [], [
                'run_id' => $locked->run_id,
                'history_run_id' => $history->run_id,
                'forecast_run_id' => $forecast->run_id,
                'result_count' => $forecast->result_count,
                'effect' => DemandForecastContract::OPERATIONAL_EFFECT,
            ]);
        });
    }

    private function markRunning(string $runId, int $actorId): DemandForecastExecutionRun
    {
        return DB::transaction(function () use ($runId, $actorId): DemandForecastExecutionRun {
            $run = DemandForecastExecutionRun::query()
                ->with('historyExport')
                ->where('run_id', $runId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($run->requested_by !== $actorId || $run->status !== DemandForecastExecutionStatus::Queued) {
                throw new DemandForecastExecutionException('RUN_STATE_CONFLICT');
            }

            $run->forceFill([
                'status' => DemandForecastExecutionStatus::Running,
                'started_at' => now(),
            ])->save();

            return $run;
        }, 3);
    }

    private function executeProcess(DemandForecastExecutionRun $run): string
    {
        $binary = (string) config('intelligence.demand_forecasting.python_binary');
        $script = (string) config('intelligence.demand_forecasting.runtime_script');
        $model = $this->modelArtifact->configuredPath();
        $timeout = (int) config('intelligence.demand_forecasting.runtime_timeout_seconds');
        if ($binary === '' || $script === '' || ! is_file($script) || $timeout < 1 || $timeout > 120) {
            throw new DemandForecastExecutionException('RUNTIME_CONFIGURATION_INVALID');
        }

        $disk = Storage::disk((string) config('intelligence.demand_forecasting.disk'));
        $runtimeDirectory = null;
        try {
            $runtimeDirectory = $disk->path('intelligence/demand-runtime/'.$run->run_id);
            File::ensureDirectoryExists($runtimeDirectory, 0700, true);
            $manifestPath = $runtimeDirectory.DIRECTORY_SEPARATOR.'manifest.json';
            $manifest = json_encode(
                $run->historyExport->manifest(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )."\n";
            if (file_put_contents($manifestPath, $manifest, LOCK_EX) !== strlen($manifest)) {
                throw new DemandForecastExecutionException('MANIFEST_WRITE_FAILED');
            }
            chmod($manifestPath, 0600);
            $snapshotPath = $disk->path($run->historyExport->stored_path);

            $result = Process::path(base_path())
                ->timeout($timeout)
                ->env([
                    'PYTHONDONTWRITEBYTECODE' => '1',
                    'PYTHONHASHSEED' => '20260814',
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
                ])
                ->run([
                    $binary,
                    $script,
                    '--snapshot',
                    $snapshotPath,
                    '--manifest',
                    $manifestPath,
                    '--model-bundle',
                    $model,
                    '--stdout',
                ]);
        } catch (ProcessTimedOutException) {
            throw new DemandForecastExecutionException('HGB_PROCESS_TIMEOUT');
        } catch (DemandForecastExecutionException $exception) {
            throw $exception;
        } catch (JsonException) {
            throw new DemandForecastExecutionException('MANIFEST_ENCODING_FAILED');
        } catch (Throwable) {
            throw new DemandForecastExecutionException('HGB_PROCESS_START_FAILED');
        } finally {
            if (is_string($runtimeDirectory) && is_dir($runtimeDirectory)) {
                File::deleteDirectory($runtimeDirectory);
            }
        }

        if ($result->failed()) {
            throw new DemandForecastExecutionException('HGB_PROCESS_FAILED');
        }
        $output = $result->output();
        $maximumBytes = (int) config('intelligence.demand_forecasting.max_upload_kilobytes') * 1024;
        if ($output === '' || strlen($output) > $maximumBytes) {
            throw new DemandForecastExecutionException('HGB_OUTPUT_INVALID');
        }

        return $output;
    }
}
