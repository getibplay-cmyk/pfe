<?php

namespace App\Jobs;

use App\Actions\Fleet\ExecuteOperationalFleetReallocationPlan;
use App\Enums\FleetReallocationPlanningRunStatus;
use App\Exceptions\FleetReallocationPlanningException;
use App\Models\FleetReallocationPlanningRun;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\FleetReallocation\FleetReallocationContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class RunOperationalFleetReallocationPlan implements ShouldQueue
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

    public function handle(ExecuteOperationalFleetReallocationPlan $execute): void
    {
        $execute->handle($this->runId, $this->tenantId, $this->actorId);
    }

    public function failed(?Throwable $exception): void
    {
        $failureCode = $exception instanceof FleetReallocationPlanningException
            ? $exception->failureCode()
            : 'INTERNAL_FAILURE';
        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $failureCode) !== 1) {
            $failureCode = 'INTERNAL_FAILURE';
        }

        $updated = DB::table('fleet_reallocation_planning_runs')
            ->where('tenant_id', $this->tenantId)
            ->where('run_id', $this->runId)
            ->whereIn('status', [
                FleetReallocationPlanningRunStatus::Queued->value,
                FleetReallocationPlanningRunStatus::Running->value,
            ])
            ->update([
                'status' => FleetReallocationPlanningRunStatus::Failed->value,
                'failure_code' => $failureCode,
                'started_at' => DB::raw('COALESCE(started_at, CURRENT_TIMESTAMP)'),
                'finished_at' => now(),
            ]);
        if ($updated !== 1) {
            return;
        }

        try {
            $run = FleetReallocationPlanningRun::withoutGlobalScopes()
                ->where('tenant_id', $this->tenantId)
                ->where('run_id', $this->runId)
                ->first();
            if ($run !== null) {
                app(AuditRecorder::class)->record('fleet.reallocation_planning.run_failed', $run, [], [
                    'run_id' => $this->runId,
                    'failure_code' => $failureCode,
                    'effect' => FleetReallocationContract::OPERATIONAL_EFFECT,
                ]);
            }
        } catch (Throwable) {
            // The terminal register remains authoritative if audit recording is unavailable.
        }
    }
}
