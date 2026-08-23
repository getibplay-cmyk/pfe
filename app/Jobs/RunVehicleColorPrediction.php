<?php

namespace App\Jobs;

use App\Actions\Intelligence\ExecuteVehicleColorPrediction;
use App\Exceptions\VehicleColorExecutionException;
use App\Models\VehicleColorPredictionRun;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\VehicleColor\VehicleColorContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class RunVehicleColorPrediction implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 35;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $runId,
        public readonly int $tenantId,
        public readonly int $actorId,
    ) {}

    public function handle(ExecuteVehicleColorPrediction $execute): void
    {
        $execute->handle($this->runId, $this->tenantId, $this->actorId);
    }

    public function failed(?Throwable $exception): void
    {
        $failureCode = $exception instanceof VehicleColorExecutionException
            ? $exception->failureCode()
            : 'INTERNAL_FAILURE';
        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $failureCode) !== 1) {
            $failureCode = 'INTERNAL_FAILURE';
        }

        $updated = DB::table('vehicle_color_prediction_runs')
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
            $run = VehicleColorPredictionRun::withoutGlobalScopes()
                ->where('tenant_id', $this->tenantId)
                ->where('run_id', $this->runId)
                ->first();
            if ($run !== null) {
                app(AuditRecorder::class)->record(
                    'prediction.vehicle_color.run_failed',
                    $run,
                    [],
                    [
                        'run_id' => $this->runId,
                        'failure_code' => $failureCode,
                        'effect' => VehicleColorContract::OPERATIONAL_EFFECT,
                    ],
                );
            }
        } catch (Throwable) {
            // Le registre conserve déjà l’échec si l’audit est indisponible.
        }
    }
}
