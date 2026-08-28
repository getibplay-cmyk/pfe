<?php

namespace App\Actions\Intelligence;

use App\Enums\VehiclePlatePredictionStatus;
use App\Exceptions\VehiclePlateHybridExecutionException;
use App\Models\User;
use App\Models\VehiclePlatePredictionRun;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\VehiclePlate\VehiclePlateHybridContract;
use App\Support\Intelligence\VehiclePlate\VehiclePlateHybridResultValidator;
use App\Support\Intelligence\VehiclePlate\VehiclePlateHybridRuntime;
use App\Support\Intelligence\VehiclePlate\VehiclePlateInputArtifact;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ExecuteVehiclePlatePrediction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly VehiclePlateInputArtifact $inputArtifact,
        private readonly VehiclePlateHybridRuntime $runtime,
        private readonly VehiclePlateHybridResultValidator $resultValidator,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(string $runId, int $tenantId, int $actorId): void
    {
        $this->context->run($tenantId, function () use ($runId, $tenantId, $actorId): void {
            $run = $this->markRunning($runId, $actorId);
            $actor = User::query()
                ->whereKey($actorId)
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->first();
            if (! (bool) config('intelligence.vehicle_plate_hybrid_review.enabled')
                || $actor === null
                || ! $actor->hasPermission('prediction.view')
                || ! $actor->hasPermission('prediction.plate.review')
                || ($actor->agency_id !== null && $actor->agency_id !== $run->agency_id)) {
                throw new VehiclePlateHybridExecutionException('RUN_ACTOR_NOT_AUTHORIZED');
            }
            if ($run->vehicle === null || $run->vehicle->agency_id !== $run->agency_id) {
                throw new VehiclePlateHybridExecutionException('VEHICLE_UNAVAILABLE');
            }
            if (! $this->inputArtifact->valid($run)) {
                throw new VehiclePlateHybridExecutionException('INPUT_ARTIFACT_INVALID');
            }

            $inputPath = Storage::disk(
                (string) config('intelligence.vehicle_plate_hybrid_review.disk'),
            )->path($run->input_stored_path);
            $output = $this->runtime->execute($run, $inputPath);
            $validated = $this->resultValidator->validate($output, $run->run_id);

            DB::transaction(function () use ($run, $validated): void {
                $candidate = VehiclePlatePredictionRun::query()
                    ->where('run_id', $run->run_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($candidate->status !== VehiclePlatePredictionStatus::Running) {
                    throw new VehiclePlateHybridExecutionException('RUN_STATE_CONFLICT');
                }

                $candidate->forceFill([
                    'status' => VehiclePlatePredictionStatus::Succeeded,
                    'suggestion_status' => $validated->status,
                    'suggested_canonical' => $validated->canonical,
                    'display_text' => $validated->displayText,
                    'confidence' => $validated->confidence,
                    'suggestion_source' => $validated->source,
                    'fallback_executed' => $validated->fallbackExecuted,
                    'finished_at' => now(),
                ])->save();

                // Plate text is deliberately absent from the audit metadata.
                $this->audit->record('prediction.vehicle_plate.run_succeeded', $candidate, [], [
                    'run_id' => $candidate->run_id,
                    'vehicle_id' => $candidate->vehicle_id,
                    'suggestion_status' => $candidate->suggestion_status,
                    'confidence' => (float) $candidate->confidence,
                    'source' => $candidate->suggestion_source,
                    'fallback_executed' => $candidate->fallback_executed,
                    'human_validation_required' => true,
                    'effect' => VehiclePlateHybridContract::OPERATIONAL_EFFECT,
                ]);
            }, 3);
        });
    }

    private function markRunning(string $runId, int $actorId): VehiclePlatePredictionRun
    {
        return DB::transaction(function () use ($runId, $actorId): VehiclePlatePredictionRun {
            $run = VehiclePlatePredictionRun::query()
                ->with('vehicle')
                ->where('run_id', $runId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($run->requested_by !== $actorId || $run->status !== VehiclePlatePredictionStatus::Queued) {
                throw new VehiclePlateHybridExecutionException('RUN_STATE_CONFLICT');
            }

            $run->forceFill([
                'status' => VehiclePlatePredictionStatus::Running,
                'started_at' => now(),
            ])->save();

            return $run;
        }, 3);
    }
}
