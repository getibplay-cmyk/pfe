<?php

namespace App\Actions\Intelligence;

use App\Enums\RentalUsageAnomalyRunStatus;
use App\Exceptions\RentalUsageAnomalyAlreadyActiveException;
use App\Exceptions\RentalUsageAnomalyExecutionException;
use App\Jobs\RunRentalUsageAnomalyScreening;
use App\Models\IntelligenceDatasetExportRun;
use App\Models\RentalUsageAnomalyRun;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\RentalUsageAnomaly\RentalUsageAnomalyContract;
use App\Support\Intelligence\RentalUsageAnomaly\RentalUsageAnomalySnapshotInspector;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class QueueRentalUsageAnomalyRun
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RentalUsageAnomalySnapshotInspector $snapshotInspector,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(IntelligenceDatasetExportRun $export, User $actor): RentalUsageAnomalyRun
    {
        $this->assertAllowed($export, $actor);
        if (! $this->runtimeReady()) {
            throw new RentalUsageAnomalyExecutionException('RUNTIME_CONFIGURATION_INVALID');
        }
        $this->snapshotInspector->inspect($export);

        $run = DB::transaction(function () use ($export, $actor): RentalUsageAnomalyRun {
            DB::selectOne(
                'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
                ['rental-usage-anomaly|'.$export->tenant_id.'|'.$export->id],
            );
            $lockedExport = IntelligenceDatasetExportRun::query()->lockForUpdate()->findOrFail($export->id);
            $this->recoverStaleRuns($lockedExport);
            if (RentalUsageAnomalyRun::query()
                ->where('intelligence_dataset_export_run_id', $lockedExport->id)
                ->whereIn('status', [
                    RentalUsageAnomalyRunStatus::Queued->value,
                    RentalUsageAnomalyRunStatus::Running->value,
                ])->exists()) {
                throw new RentalUsageAnomalyAlreadyActiveException;
            }

            $run = RentalUsageAnomalyRun::create([
                'agency_id' => $lockedExport->agency_id,
                'run_id' => (string) Str::uuid(),
                'intelligence_dataset_export_run_id' => $lockedExport->id,
                'requested_by' => $actor->id,
                'status' => RentalUsageAnomalyRunStatus::Queued,
                'source_row_count' => $lockedExport->row_count,
                'minimum_rows' => RentalUsageAnomalyContract::MINIMUM_ROWS,
                'default_budget_basis_points' => RentalUsageAnomalyContract::DEFAULT_BUDGET_BASIS_POINTS,
                'primary_model' => RentalUsageAnomalyContract::PRIMARY_MODEL,
                'primary_version' => RentalUsageAnomalyContract::PRIMARY_VERSION,
                'challenger_model' => RentalUsageAnomalyContract::CHALLENGER_MODEL,
                'challenger_version' => RentalUsageAnomalyContract::CHALLENGER_VERSION,
                'random_state' => RentalUsageAnomalyContract::RANDOM_STATE,
                'runtime_sha256' => hash_file(
                    'sha256',
                    (string) config('intelligence.rental_usage_anomaly.runtime_script'),
                ),
                'compute' => 'CPU',
                'operational_effect' => RentalUsageAnomalyContract::OPERATIONAL_EFFECT,
                'requested_at' => now(),
            ]);

            $this->audit->record('prediction.rental_usage_anomaly.run_queued', $run, [], [
                'run_id' => $run->run_id,
                'export_run_id' => $lockedExport->run_id,
                'source_row_count' => $run->source_row_count,
                'primary_model' => $run->primary_model,
                'challenger_role' => 'comparison_only',
                'default_review_budget_basis_points' => $run->default_budget_basis_points,
                'effect' => RentalUsageAnomalyContract::OPERATIONAL_EFFECT,
            ]);

            return $run;
        }, 3);

        try {
            RunRentalUsageAnomalyScreening::dispatch($run->run_id, $run->tenant_id, $actor->id)
                ->onQueue((string) config('intelligence.rental_usage_anomaly.runtime_queue'));
        } catch (Throwable) {
            try {
                DB::table('rental_usage_anomaly_runs')
                    ->where('tenant_id', $run->tenant_id)
                    ->where('run_id', $run->run_id)
                    ->where('status', RentalUsageAnomalyRunStatus::Queued->value)
                    ->update([
                        'status' => RentalUsageAnomalyRunStatus::Failed->value,
                        'failure_code' => 'QUEUE_DISPATCH_FAILED',
                        'started_at' => now(),
                        'finished_at' => now(),
                    ]);
            } catch (Throwable) {
                // The HTTP request remains closed if the database becomes unavailable.
            }
            throw new RentalUsageAnomalyExecutionException('QUEUE_DISPATCH_FAILED');
        }

        return $run;
    }

    private function assertAllowed(IntelligenceDatasetExportRun $export, User $actor): void
    {
        $contextAgency = $this->context->agencyId();
        if ($actor->tenant_id !== $export->tenant_id
            || $this->context->tenantId() !== $export->tenant_id
            || ! $actor->is_active
            || ! $actor->hasPermission('prediction.view')
            || ! $actor->hasPermission('prediction.anomaly.review')
            || ($actor->agency_id !== null && $actor->agency_id !== $export->agency_id)
            || ($contextAgency !== null && $contextAgency !== $export->agency_id)) {
            throw new AuthorizationException;
        }
    }

    private function runtimeReady(): bool
    {
        $timeout = (int) config('intelligence.rental_usage_anomaly.runtime_timeout_seconds');

        return (bool) config('intelligence.rental_usage_anomaly.enabled')
            && (string) config('intelligence.rental_usage_anomaly.python_binary') !== ''
            && is_file((string) config('intelligence.rental_usage_anomaly.runtime_script'))
            && (int) config('intelligence.rental_usage_anomaly.minimum_rows') === RentalUsageAnomalyContract::MINIMUM_ROWS
            && $timeout >= 10
            && $timeout <= 120;
    }

    private function recoverStaleRuns(IntelligenceDatasetExportRun $export): void
    {
        $staleAfter = (int) config('intelligence.rental_usage_anomaly.runtime_stale_after_seconds');
        if ($staleAfter < 120) {
            return;
        }
        $cutoff = now()->subSeconds($staleAfter);
        $runs = RentalUsageAnomalyRun::query()
            ->where('intelligence_dataset_export_run_id', $export->id)
            ->whereIn('status', [RentalUsageAnomalyRunStatus::Queued->value, RentalUsageAnomalyRunStatus::Running->value])
            ->where(function ($query) use ($cutoff): void {
                $query->where(function ($queued) use ($cutoff): void {
                    $queued->where('status', RentalUsageAnomalyRunStatus::Queued->value)
                        ->where('requested_at', '<', $cutoff);
                })->orWhere(function ($running) use ($cutoff): void {
                    $running->where('status', RentalUsageAnomalyRunStatus::Running->value)
                        ->where('started_at', '<', $cutoff);
                });
            })
            ->lockForUpdate()
            ->get();
        foreach ($runs as $run) {
            $run->forceFill([
                'status' => RentalUsageAnomalyRunStatus::Failed,
                'failure_code' => 'RUN_STALE_RECOVERED',
                'started_at' => $run->started_at ?? $run->requested_at,
                'finished_at' => now(),
            ])->save();
        }
    }
}
