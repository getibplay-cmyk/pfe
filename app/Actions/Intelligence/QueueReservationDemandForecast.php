<?php

namespace App\Actions\Intelligence;

use App\Enums\DemandForecastExecutionStatus;
use App\Enums\IntelligenceCapability;
use App\Exceptions\DemandForecastRuntimeUnavailableException;
use App\Models\DemandForecastExecutionRun;
use App\Models\DemandHistoryExportRun;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\DemandForecasting\DemandForecastContract;
use App\Support\Intelligence\DemandForecasting\DemandForecastRuntimeReadiness;
use App\Support\Intelligence\TenantIntelligenceAccess;
use App\Support\Tenancy\AgencyAccess;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class QueueReservationDemandForecast
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AgencyAccess $agencyAccess,
        private readonly TenantIntelligenceAccess $tenantAccess,
        private readonly DemandForecastRuntimeReadiness $readiness,
        private readonly CreateDemandHistoryExport $history,
        private readonly QueueDemandForecastExecution $queue,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(int $requestedAgencyId, User $actor): DemandForecastExecutionRun
    {
        $agencyId = $this->agencyAccess->required($requestedAgencyId);
        $this->assertAllowed($agencyId, $actor);
        $this->tenantAccess->ensureAuthorized(IntelligenceCapability::DemandForecast);
        if (! $this->readiness->ready()) {
            throw new DemandForecastRuntimeUnavailableException;
        }

        $createdHistory = null;
        try {
            return DB::transaction(function () use (
                $agencyId,
                $actor,
                &$createdHistory,
            ): DemandForecastExecutionRun {
                DB::selectOne(
                    'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
                    ['reservation-demand-forecast|'.$this->context->tenantId().'|'.$agencyId],
                );
                $this->recoverStaleRuns($agencyId);

                $active = DemandForecastExecutionRun::query()
                    ->where('agency_id', $agencyId)
                    ->whereIn('status', [
                        DemandForecastExecutionStatus::Queued->value,
                        DemandForecastExecutionStatus::Running->value,
                    ])
                    ->latest('requested_at')
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();
                if ($active !== null) {
                    return $active;
                }

                $createdHistory = $this->history->handleForForecast($agencyId, $actor);

                return $this->queue->handle($createdHistory, $actor);
            }, 3);
        } catch (Throwable $exception) {
            $deleteOrphan = false;
            try {
                $deleteOrphan = $createdHistory instanceof DemandHistoryExportRun
                    && ! DB::table('demand_forecast_execution_runs')
                        ->where('tenant_id', $createdHistory->tenant_id)
                        ->where('demand_history_export_run_id', $createdHistory->id)
                        ->exists();
            } catch (Throwable) {
                // En cas d’incertitude sur la transaction, préserver le snapshot privé.
            }
            if ($deleteOrphan) {
                try {
                    Storage::disk((string) config('intelligence.demand_forecasting.disk'))
                        ->delete((string) $createdHistory->stored_path);
                } catch (Throwable) {
                    // L’erreur métier initiale reste prioritaire sur le nettoyage secondaire.
                }
            }

            throw $exception;
        }
    }

    private function assertAllowed(int $agencyId, User $actor): void
    {
        if ($actor->tenant_id !== $this->context->tenantId()
            || ! $actor->is_active
            || ! $actor->hasPermission('reservation.view')
            || ! $actor->hasPermission('prediction.view')
            || ! $actor->hasPermission('prediction.forecast.import')
            || ($actor->agency_id !== null && $actor->agency_id !== $agencyId)) {
            throw new AuthorizationException;
        }
    }

    private function recoverStaleRuns(int $agencyId): void
    {
        $staleAfterSeconds = (int) config(
            'intelligence.demand_forecasting.runtime_stale_after_seconds',
        );
        if ($staleAfterSeconds < 60) {
            return;
        }

        $cutoff = now()->subSeconds($staleAfterSeconds);
        $runs = DemandForecastExecutionRun::query()
            ->where('agency_id', $agencyId)
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
