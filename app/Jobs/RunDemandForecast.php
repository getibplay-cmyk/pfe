<?php

namespace App\Jobs;

use App\Actions\Intelligence\ExecuteDemandForecastExecution;
use App\Exceptions\DemandForecastExecutionException;
use App\Models\DemandForecastExecutionRun;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\DemandForecasting\DemandForecastContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class RunDemandForecast implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 65;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $runId,
        public readonly int $tenantId,
        public readonly int $actorId,
    ) {}

    public function handle(ExecuteDemandForecastExecution $execute): void
    {
        $execute->handle($this->runId, $this->tenantId, $this->actorId);
    }

    public function failed(?Throwable $exception): void
    {
        $failureCode = $exception instanceof DemandForecastExecutionException
            ? $exception->failureCode()
            : 'INTERNAL_FAILURE';
        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $failureCode) !== 1) {
            $failureCode = 'INTERNAL_FAILURE';
        }

        $updated = DB::table('demand_forecast_execution_runs')
            ->where('tenant_id', $this->tenantId)
            ->where('run_id', $this->runId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                'failure_code' => $failureCode,
                'started_at' => DB::raw('COALESCE(started_at, CURRENT_TIMESTAMP)'),
                'finished_at' => now(),
            ]);
        if ($updated !== 1) {
            return;
        }

        try {
            $run = DemandForecastExecutionRun::withoutGlobalScopes()
                ->where('tenant_id', $this->tenantId)
                ->where('run_id', $this->runId)
                ->first();
            if ($run !== null) {
                app(AuditRecorder::class)->record(
                    'prediction.demand_forecast.execution_failed',
                    $run,
                    [],
                    [
                        'run_id' => $this->runId,
                        'failure_code' => $failureCode,
                        'effect' => DemandForecastContract::OPERATIONAL_EFFECT,
                    ],
                );
            }
        } catch (Throwable) {
            // Le registre conserve déjà l’échec si l’audit est indisponible.
        }
    }
}
