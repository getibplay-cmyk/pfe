<?php

namespace App\Jobs;

use App\Actions\Intelligence\ExecuteFleetReallocationRun;
use App\Exceptions\FleetReallocationExecutionException;
use App\Models\FleetReallocationRun;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\FleetReallocation\FleetReallocationContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class RunFleetReallocation implements ShouldQueue
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

    public function handle(ExecuteFleetReallocationRun $execute): void
    {
        $execute->handle($this->runId, $this->tenantId, $this->actorId);
    }

    public function failed(?Throwable $exception): void
    {
        $failureCode = $exception instanceof FleetReallocationExecutionException
            ? $exception->failureCode()
            : 'INTERNAL_FAILURE';
        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $failureCode) !== 1) {
            $failureCode = 'INTERNAL_FAILURE';
        }

        $updated = DB::table('fleet_reallocation_runs')
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
            $run = FleetReallocationRun::withoutGlobalScopes()
                ->where('tenant_id', $this->tenantId)
                ->where('run_id', $this->runId)
                ->first();
            if ($run !== null) {
                app(AuditRecorder::class)->record(
                    'prediction.fleet_reallocation.run_failed',
                    $run,
                    [],
                    [
                        'run_id' => $this->runId,
                        'failure_code' => $failureCode,
                        'effect' => FleetReallocationContract::OPERATIONAL_EFFECT,
                    ],
                );
            }
        } catch (Throwable) {
            // Le registre append-only garde déjà l’échec si l’audit est indisponible.
        }
    }
}
