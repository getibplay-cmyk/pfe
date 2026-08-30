<?php

namespace App\Actions\Fleet;

use App\Enums\FleetReallocationPlanningRunStatus;
use App\Enums\IntelligenceCapability;
use App\Exceptions\FleetReallocationPlanningException;
use App\Jobs\RunOperationalFleetReallocationPlan;
use App\Models\FleetReallocationPlanningRun;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Fleet\BuildOperationalFleetReallocationSnapshot;
use App\Support\Intelligence\FleetReallocation\FleetReallocationContract;
use App\Support\Intelligence\TenantIntelligenceAccess;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QueueOperationalFleetReallocationPlan
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantIntelligenceAccess $tenantAccess,
        private readonly BuildOperationalFleetReallocationSnapshot $snapshotBuilder,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(User $actor): FleetReallocationPlanningRun
    {
        $this->assertAllowed($actor);
        $this->tenantAccess->ensureUsable(IntelligenceCapability::FleetReallocation);

        return DB::transaction(function () use ($actor): FleetReallocationPlanningRun {
            DB::selectOne(
                'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
                ['fleet-reallocation-planning|'.$this->context->tenantId()],
            );
            $this->recoverStaleRuns();
            $snapshot = $this->snapshotBuilder->build();

            $existing = FleetReallocationPlanningRun::query()
                ->where('input_fingerprint', $snapshot->inputFingerprint)
                ->whereIn('status', [
                    FleetReallocationPlanningRunStatus::Queued->value,
                    FleetReallocationPlanningRunStatus::Running->value,
                ])
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $run = FleetReallocationPlanningRun::create([
                'run_id' => (string) Str::uuid(),
                'requested_by' => $actor->getKey(),
                'source_kind' => 'rentfleet_operational',
                'status' => FleetReallocationPlanningRunStatus::Queued,
                'reference_date' => $snapshot->referenceDate,
                'input_fingerprint' => $snapshot->inputFingerprint,
                'distance_matrix_fingerprint' => $snapshot->distanceMatrixFingerprint,
                'runtime_sha256' => $snapshot->runtimeSha256,
                'snapshot' => $snapshot->payload,
                'operational_effect' => FleetReallocationContract::OPERATIONAL_EFFECT,
                'requested_at' => now(),
            ]);

            $this->audit->record('fleet.reallocation_planning.run_queued', $run, [], [
                'run_id' => $run->run_id,
                'source_kind' => $run->source_kind,
                'reference_date' => $run->reference_date->toDateString(),
                'status' => FleetReallocationPlanningRunStatus::Queued->value,
                'effect' => FleetReallocationContract::OPERATIONAL_EFFECT,
            ]);

            RunOperationalFleetReallocationPlan::dispatch(
                $run->run_id,
                $run->tenant_id,
                (int) $actor->getKey(),
            )->onQueue((string) config('intelligence.fleet_reallocation.runtime_queue'))->afterCommit();

            return $run;
        }, 3);
    }

    private function assertAllowed(User $actor): void
    {
        if ($actor->tenant_id !== $this->context->tenantId()
            || ! $actor->is_active
            || ! in_array($actor->role?->slug, ['tenant-owner', 'fleet-manager'], true)
            || ! $actor->hasPermission('prediction.demo.review')) {
            throw new AuthorizationException;
        }
    }

    private function recoverStaleRuns(): void
    {
        $seconds = (int) config('intelligence.fleet_reallocation.runtime_stale_after_seconds');
        if ($seconds < 60) {
            throw new FleetReallocationPlanningException('RUNTIME_CONFIGURATION_INVALID');
        }

        $cutoff = now()->subSeconds($seconds);
        $runs = FleetReallocationPlanningRun::query()
            ->whereIn('status', [
                FleetReallocationPlanningRunStatus::Queued->value,
                FleetReallocationPlanningRunStatus::Running->value,
            ])
            ->where(function ($query) use ($cutoff): void {
                $query->where(function ($queued) use ($cutoff): void {
                    $queued->where('status', FleetReallocationPlanningRunStatus::Queued->value)
                        ->where('requested_at', '<', $cutoff);
                })->orWhere(function ($running) use ($cutoff): void {
                    $running->where('status', FleetReallocationPlanningRunStatus::Running->value)
                        ->where('started_at', '<', $cutoff);
                });
            })
            ->lockForUpdate()
            ->get();

        foreach ($runs as $run) {
            $run->forceFill([
                'status' => FleetReallocationPlanningRunStatus::Failed,
                'failure_code' => 'RUN_STALE_RECOVERED',
                'started_at' => $run->started_at ?? $run->requested_at,
                'finished_at' => now(),
            ])->save();
            $this->audit->record('fleet.reallocation_planning.run_failed', $run, [], [
                'run_id' => $run->run_id,
                'failure_code' => 'RUN_STALE_RECOVERED',
                'effect' => FleetReallocationContract::OPERATIONAL_EFFECT,
            ]);
        }
    }
}
