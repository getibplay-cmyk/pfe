<?php

namespace App\Actions\Intelligence;

use App\Enums\FleetReallocationRunStatus;
use App\Enums\IntelligenceCapability;
use App\Exceptions\FleetReallocationRunAlreadyActiveException;
use App\Jobs\RunFleetReallocation;
use App\Models\FleetReallocationRun;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\FleetReallocation\FleetReallocationContract;
use App\Support\Intelligence\TenantIntelligenceAccess;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class QueueFleetReallocationRun
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantIntelligenceAccess $tenantAccess,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(User $actor, int $forecastHorizon): FleetReallocationRun
    {
        $this->assertAllowed($actor, $forecastHorizon);
        $this->tenantAccess->ensureUsable(IntelligenceCapability::FleetReallocation);

        return DB::transaction(function () use ($actor, $forecastHorizon): FleetReallocationRun {
            DB::selectOne(
                'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
                ['fleet-reallocation-runtime|'.$this->context->tenantId()],
            );
            $this->recoverStaleRuns();
            if (FleetReallocationRun::query()
                ->whereIn('status', [
                    FleetReallocationRunStatus::Queued->value,
                    FleetReallocationRunStatus::Running->value,
                ])->exists()) {
                throw new FleetReallocationRunAlreadyActiveException;
            }

            $run = FleetReallocationRun::create([
                'run_id' => (string) Str::uuid(),
                'requested_by' => $actor->id,
                'forecast_horizon' => $forecastHorizon,
                'scenario_number' => $forecastHorizon,
                'status' => FleetReallocationRunStatus::Queued,
                'operational_effect' => FleetReallocationContract::OPERATIONAL_EFFECT,
                'requested_at' => now(),
            ]);

            $this->audit->record('prediction.fleet_reallocation.run_queued', $run, [], [
                'run_id' => $run->run_id,
                'forecast_horizon' => $run->forecast_horizon,
                'status' => FleetReallocationRunStatus::Queued->value,
                'effect' => FleetReallocationContract::OPERATIONAL_EFFECT,
            ]);

            RunFleetReallocation::dispatch($run->run_id, $run->tenant_id, $actor->id)
                ->onQueue((string) config('intelligence.fleet_reallocation.runtime_queue'))
                ->afterCommit();

            return $run;
        }, 3);
    }

    private function assertAllowed(User $actor, int $forecastHorizon): void
    {
        if ($forecastHorizon < 1
            || $forecastHorizon > 7
            || $actor->tenant_id !== $this->context->tenantId()
            || $actor->agency_id !== null
            || $this->context->agencyId() !== null
            || ! $actor->is_active
            || ! $actor->hasPermission('prediction.demo.review')) {
            throw new AuthorizationException;
        }
    }

    private function recoverStaleRuns(): void
    {
        $staleAfterSeconds = (int) config(
            'intelligence.fleet_reallocation.runtime_stale_after_seconds',
        );
        if ($staleAfterSeconds < 60) {
            return;
        }

        $cutoff = now()->subSeconds($staleAfterSeconds);
        $runs = FleetReallocationRun::query()
            ->whereIn('status', [
                FleetReallocationRunStatus::Queued->value,
                FleetReallocationRunStatus::Running->value,
            ])
            ->where(function ($query) use ($cutoff): void {
                $query->where(function ($queued) use ($cutoff): void {
                    $queued->where('status', FleetReallocationRunStatus::Queued->value)
                        ->where('requested_at', '<', $cutoff);
                })->orWhere(function ($running) use ($cutoff): void {
                    $running->where('status', FleetReallocationRunStatus::Running->value)
                        ->where('started_at', '<', $cutoff);
                });
            })
            ->lockForUpdate()
            ->get();

        foreach ($runs as $run) {
            $run->forceFill([
                'status' => FleetReallocationRunStatus::Failed,
                'failure_code' => 'RUN_STALE_RECOVERED',
                'started_at' => $run->started_at ?? $run->requested_at,
                'finished_at' => now(),
            ])->save();
            $this->audit->record('prediction.fleet_reallocation.run_failed', $run, [], [
                'run_id' => $run->run_id,
                'failure_code' => 'RUN_STALE_RECOVERED',
                'effect' => FleetReallocationContract::OPERATIONAL_EFFECT,
            ]);
        }
    }
}
