<?php

namespace App\Actions\Intelligence;

use App\Enums\IntelligenceCapability;
use App\Enums\VehiclePlatePredictionStatus;
use App\Exceptions\VehiclePlateHybridExecutionException;
use App\Models\User;
use App\Models\VehiclePlatePredictionRun;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\IntelligencePrivateStorage;
use App\Support\Intelligence\TenantIntelligenceAccess;
use App\Support\Intelligence\VehiclePlate\ValidatedVehiclePlateDetection;
use App\Support\Intelligence\VehiclePlate\VehiclePlateDetectorRuntime;
use App\Support\Intelligence\VehiclePlate\VehiclePlateHybridContract;
use App\Support\Intelligence\VehiclePlate\VehiclePlateHybridResultValidator;
use App\Support\Intelligence\VehiclePlate\VehiclePlateHybridRuntime;
use App\Support\Intelligence\VehiclePlate\VehiclePlateInputArtifact;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class ExecuteVehiclePlatePrediction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantIntelligenceAccess $tenantAccess,
        private readonly VehiclePlateInputArtifact $inputArtifact,
        private readonly VehiclePlateDetectorRuntime $detectorRuntime,
        private readonly VehiclePlateHybridRuntime $runtime,
        private readonly VehiclePlateHybridResultValidator $resultValidator,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(string $runId, int $tenantId, int $actorId): void
    {
        $this->context->run($tenantId, function () use ($runId, $tenantId, $actorId): void {
            if (! $this->tenantAccess->usable(IntelligenceCapability::VehiclePlate, $tenantId)) {
                throw new VehiclePlateHybridExecutionException('TENANT_INTELLIGENCE_UNAVAILABLE');
            }
            $run = $this->markRunning($runId, $actorId);
            $actor = User::query()
                ->whereKey($actorId)
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->first();
            $preparatory = $run->vehicle_id === null;
            $actorCanExecute = $actor !== null && ($preparatory
                ? $actor->hasPermission('vehicle.create')
                : ($actor->hasPermission('prediction.view')
                    && $actor->hasPermission('prediction.plate.review')));
            if ($actor === null
                || ! $actorCanExecute
                || ($actor->agency_id !== null && $actor->agency_id !== $run->agency_id)) {
                throw new VehiclePlateHybridExecutionException('RUN_ACTOR_NOT_AUTHORIZED');
            }
            if (! $preparatory
                && ($run->vehicle === null || $run->vehicle->agency_id !== $run->agency_id)) {
                throw new VehiclePlateHybridExecutionException('VEHICLE_UNAVAILABLE');
            }
            if (! $this->inputArtifact->valid($run)) {
                throw new VehiclePlateHybridExecutionException('INPUT_ARTIFACT_INVALID');
            }

            try {
                $disk = IntelligencePrivateStorage::disk(
                    'intelligence.vehicle_plate_hybrid_review.disk',
                );
                $inputPath = IntelligencePrivateStorage::path(
                    'intelligence.vehicle_plate_hybrid_review.disk',
                    (string) $run->input_stored_path,
                );
            } catch (\Throwable) {
                throw new VehiclePlateHybridExecutionException('INPUT_ARTIFACT_INVALID');
            }
            $detection = null;
            $cropStoredPath = null;
            try {
                $ocrPath = $inputPath;
                if ($run->usesDetector()) {
                    $detection = $this->detectorRuntime->execute($run, $inputPath);
                    if ($detection->status === 'no_detection') {
                        throw new VehiclePlateHybridExecutionException('PLATE_NOT_DETECTED');
                    }
                    if ($detection->status === 'ambiguous') {
                        throw new VehiclePlateHybridExecutionException('PLATE_DETECTION_AMBIGUOUS');
                    }
                    if (! $detection->detected()
                        || ! is_string($detection->cropContents)
                        || ! is_int($detection->cropBytes)
                        || ! is_string($detection->cropSha256)) {
                        throw new VehiclePlateHybridExecutionException('DETECTOR_OUTPUT_CROP_INVALID');
                    }
                    $cropStoredPath = 'intelligence/plate-hybrid/crops/'
                        .$run->tenant_id.'/'.$run->run_id.'.jpg';
                    try {
                        $stored = $disk->put(
                            $cropStoredPath,
                            $detection->cropContents,
                            ['visibility' => 'private'],
                        );
                    } catch (\Throwable) {
                        throw new VehiclePlateHybridExecutionException(
                            'DETECTOR_CROP_STORE_FAILED',
                        );
                    }
                    if (! $stored) {
                        throw new VehiclePlateHybridExecutionException('DETECTOR_CROP_STORE_FAILED');
                    }
                    $ocrPath = IntelligencePrivateStorage::path(
                        'intelligence.vehicle_plate_hybrid_review.disk',
                        $cropStoredPath,
                    );
                    if (! $this->storedCropMatches($ocrPath, $detection)) {
                        throw new VehiclePlateHybridExecutionException('DETECTOR_CROP_STORE_FAILED');
                    }
                }

                $output = $this->runtime->execute($run, $ocrPath);
                $validated = $this->resultValidator->validate($output, $run->run_id);

                DB::transaction(function () use (
                    $run,
                    $validated,
                    $detection,
                    $cropStoredPath,
                ): void {
                    $candidate = VehiclePlatePredictionRun::query()
                        ->where('run_id', $run->run_id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    if ($candidate->status !== VehiclePlatePredictionStatus::Running) {
                        throw new VehiclePlateHybridExecutionException('RUN_STATE_CONFLICT');
                    }

                    $updates = [
                        'status' => VehiclePlatePredictionStatus::Succeeded,
                        'suggestion_status' => $validated->status,
                        'suggested_canonical' => $validated->canonical,
                        'display_text' => $validated->displayText,
                        'confidence' => $validated->confidence,
                        'suggestion_source' => $validated->source,
                        'fallback_executed' => $validated->fallbackExecuted,
                        'finished_at' => now(),
                    ];
                    if ($detection instanceof ValidatedVehiclePlateDetection) {
                        $updates += [
                            'detector_confidence' => $detection->score,
                            'detector_candidate_count' => $detection->eligibleCount,
                            'detector_bbox' => $detection->bbox,
                            'crop_mime' => 'image/jpeg',
                            'crop_extension' => 'jpg',
                            'crop_bytes' => $detection->cropBytes,
                            'crop_width' => $detection->cropWidth,
                            'crop_height' => $detection->cropHeight,
                            'crop_sha256' => $detection->cropSha256,
                            'crop_stored_path' => $cropStoredPath,
                            'crop_bbox' => $detection->cropBbox,
                        ];
                    }
                    $candidate->forceFill($updates)->save();

                    // Plate text, paths, bounding boxes and hashes stay out of audit metadata.
                    $this->audit->record('prediction.vehicle_plate.run_succeeded', $candidate, [], [
                        'run_id' => $candidate->run_id,
                        'vehicle_id' => $candidate->vehicle_id,
                        'input_kind' => $candidate->input_kind,
                        'detector_executed' => $candidate->usesDetector(),
                        'detector_confidence' => $candidate->detector_confidence === null
                            ? null
                            : (float) $candidate->detector_confidence,
                        'detector_candidate_count' => $candidate->detector_candidate_count,
                        'suggestion_status' => $candidate->suggestion_status,
                        'confidence' => (float) $candidate->confidence,
                        'source' => $candidate->suggestion_source,
                        'fallback_executed' => $candidate->fallback_executed,
                        'human_validation_required' => true,
                        'effect' => VehiclePlateHybridContract::OPERATIONAL_EFFECT,
                    ]);
                }, 3);
            } catch (\Throwable $exception) {
                if (is_string($cropStoredPath)) {
                    IntelligencePrivateStorage::deleteAfterFailure(
                        'intelligence.vehicle_plate_hybrid_review.disk',
                        $cropStoredPath,
                    );
                }

                throw $exception;
            }
        });
    }

    private function storedCropMatches(
        string $path,
        ValidatedVehiclePlateDetection $detection,
    ): bool {
        if (! is_file($path) || is_link($path)) {
            return false;
        }
        $bytes = filesize($path);
        $sha256 = hash_file('sha256', $path);

        return $bytes === $detection->cropBytes
            && is_string($sha256)
            && is_string($detection->cropSha256)
            && hash_equals($detection->cropSha256, $sha256);
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
