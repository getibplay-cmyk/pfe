<?php

namespace App\Actions\Intelligence;

use App\Enums\DemandForecastExecutionStatus;
use App\Exceptions\DemandForecastExecutionAlreadyActiveException;
use App\Exceptions\DemandForecastRuntimeUnavailableException;
use App\Jobs\RunDemandForecast;
use App\Models\DemandForecastExecutionRun;
use App\Models\DemandHistoryExportRun;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\DemandForecasting\DemandForecastArtifactVerifier;
use App\Support\Intelligence\DemandForecasting\DemandForecastContract;
use App\Support\Intelligence\DemandForecasting\DemandForecastRuntimeReadiness;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class QueueDemandForecastExecution
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly DemandForecastRuntimeReadiness $readiness,
        private readonly DemandForecastArtifactVerifier $historyArtifact,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(DemandHistoryExportRun $history, User $actor): DemandForecastExecutionRun
    {
        $this->assertAllowed($history, $actor);
        if (! $this->readiness->ready() || ! $this->historyArtifact->validHistory($history)) {
            throw new DemandForecastRuntimeUnavailableException;
        }

        return DB::transaction(function () use ($history, $actor): DemandForecastExecutionRun {
            DB::selectOne(
                'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
                ['reservation-demand-forecast|'.$history->tenant_id.'|'.$history->agency_id],
            );
            $this->recoverStaleRuns($history);
            if (DemandForecastExecutionRun::query()
                ->where('agency_id', $history->agency_id)
                ->whereIn('status', [
                    DemandForecastExecutionStatus::Queued->value,
                    DemandForecastExecutionStatus::Running->value,
                ])->exists()) {
                throw new DemandForecastExecutionAlreadyActiveException;
            }

            $run = DemandForecastExecutionRun::create([
                'agency_id' => $history->agency_id,
                'run_id' => (string) Str::uuid(),
                'demand_history_export_run_id' => $history->id,
                'requested_by' => $actor->id,
                'status' => DemandForecastExecutionStatus::Queued,
                'model_artifact_sha256' => DemandForecastContract::MODEL_ARTIFACT_SHA256,
                'model_artifact_bytes' => DemandForecastContract::MODEL_ARTIFACT_BYTES,
                'operational_effect' => DemandForecastContract::OPERATIONAL_EFFECT,
                'requested_at' => now(),
            ]);

            $this->audit->record('prediction.demand_forecast.execution_queued', $run, [], [
                'run_id' => $run->run_id,
                'history_run_id' => $history->run_id,
                'status' => DemandForecastExecutionStatus::Queued->value,
                'effect' => DemandForecastContract::OPERATIONAL_EFFECT,
            ]);

            RunDemandForecast::dispatch($run->run_id, $run->tenant_id, $actor->id)
                ->onQueue((string) config('intelligence.demand_forecasting.runtime_queue'))
                ->afterCommit();

            return $run;
        }, 3);
    }

    private function assertAllowed(DemandHistoryExportRun $history, User $actor): void
    {
        $contextAgency = $this->context->agencyId();
        if (! (bool) config('intelligence.demand_forecasting.runtime_enabled')
            || $actor->tenant_id !== $history->tenant_id
            || $this->context->tenantId() !== $history->tenant_id
            || ! $actor->is_active
            || ! $actor->hasPermission('prediction.forecast.import')
            || ($actor->agency_id !== null && $actor->agency_id !== $history->agency_id)
            || ($contextAgency !== null && $contextAgency !== $history->agency_id)) {
            throw new AuthorizationException;
        }
    }

    private function recoverStaleRuns(DemandHistoryExportRun $history): void
    {
        $staleAfterSeconds = (int) config(
            'intelligence.demand_forecasting.runtime_stale_after_seconds',
        );
        if ($staleAfterSeconds < 60) {
            return;
        }

        $cutoff = now()->subSeconds($staleAfterSeconds);
        $runs = DemandForecastExecutionRun::query()
            ->where('agency_id', $history->agency_id)
            ->whereIn('status', [
                DemandForecastExecutionStatus::Queued->value,
                DemandForecastExecutionStatus::Running->value,
            ])
            ->where(function ($query) use ($cutoff): void {
                $query->where(function ($queued) use ($cutoff): void {
                    $queued->where('status', DemandForecastExecutionStatus::Queued->value)
                        ->where('requested_at', '<', $cutoff);
                })->orWhere(function ($running) use ($cutoff): void {
                    $running->where('status', DemandForecastExecutionStatus::Running->value)
                        ->where('started_at', '<', $cutoff);
                });
            })
            ->lockForUpdate()
            ->get();

        foreach ($runs as $run) {
            $run->forceFill([
                'status' => DemandForecastExecutionStatus::Failed,
                'failure_code' => 'RUN_STALE_RECOVERED',
                'started_at' => $run->started_at ?? $run->requested_at,
                'finished_at' => now(),
            ])->save();
            $this->audit->record('prediction.demand_forecast.execution_failed', $run, [], [
                'run_id' => $run->run_id,
                'failure_code' => 'RUN_STALE_RECOVERED',
                'effect' => DemandForecastContract::OPERATIONAL_EFFECT,
            ]);
        }
    }
}
