<?php

namespace App\Actions\Intelligence;

use App\Enums\IntelligenceCapability;
use App\Enums\VehiclePlatePredictionStatus;
use App\Exceptions\VehiclePlatePredictionAlreadyActiveException;
use App\Exceptions\VehiclePlateRuntimeUnavailableException;
use App\Jobs\RunVehiclePlatePrediction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePlatePredictionRun;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\IntelligencePrivateStorage;
use App\Support\Intelligence\TenantIntelligenceAccess;
use App\Support\Intelligence\VehiclePlate\VehiclePlateDetectorContract;
use App\Support\Intelligence\VehiclePlate\VehiclePlateHybridContract;
use App\Support\Intelligence\VehiclePlate\VehiclePlateImageSanitizer;
use App\Support\Intelligence\VehiclePlate\VehiclePlateRuntimeReadiness;
use App\Support\Tenancy\AgencyAccess;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class QueueVehiclePlatePrediction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AgencyAccess $agencyAccess,
        private readonly TenantIntelligenceAccess $tenantAccess,
        private readonly VehiclePlateRuntimeReadiness $readiness,
        private readonly VehiclePlateImageSanitizer $imageSanitizer,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(
        Vehicle $vehicle,
        UploadedFile $image,
        User $actor,
        string $inputKind,
    ): VehiclePlatePredictionRun {
        $this->assertAllowed($vehicle, $actor);

        return $this->queue($vehicle, $vehicle->agency_id, $image, $actor, $inputKind);
    }

    public function handlePreparation(
        int $agencyId,
        UploadedFile $image,
        User $actor,
        string $inputKind,
    ): VehiclePlatePredictionRun {
        $this->assertPreparationAllowed($actor);

        return $this->queue(
            null,
            $this->agencyAccess->required($agencyId),
            $image,
            $actor,
            $inputKind,
        );
    }

    private function queue(
        ?Vehicle $vehicle,
        int $agencyId,
        UploadedFile $image,
        User $actor,
        string $inputKind,
    ): VehiclePlatePredictionRun {
        $this->tenantAccess->ensureAuthorized(IntelligenceCapability::VehiclePlate);
        if (! $this->readiness->ready($inputKind)) {
            throw new VehiclePlateRuntimeUnavailableException;
        }

        $sanitized = $this->imageSanitizer->sanitize($image);
        $runId = (string) Str::uuid();
        $mime = $sanitized->mime;
        $extension = $sanitized->extension;
        $bytes = $sanitized->bytes;
        $width = $sanitized->width;
        $height = $sanitized->height;
        $sha256 = $sanitized->sha256;
        $usesDetector = $inputKind === VehiclePlateDetectorContract::FULL_IMAGE;
        $detectorModelName = $usesDetector ? VehiclePlateDetectorContract::MODEL_NAME : null;
        $detectorSha256 = $usesDetector
            ? mb_strtolower((string) config(
                'intelligence.vehicle_plate_hybrid_review.detector.model_sha256',
            ))
            : null;
        $detectorThreshold = $usesDetector
            ? (float) config('intelligence.vehicle_plate_hybrid_review.detector.threshold')
            : null;
        $detectorPadding = $usesDetector
            ? (float) config(
                'intelligence.vehicle_plate_hybrid_review.detector.crop_padding_ratio',
            )
            : null;

        $directory = 'intelligence/plate-hybrid/inputs/'.$this->context->tenantId();
        $storedPath = $directory.'/'.$runId.'.'.$extension;
        try {
            $disk = IntelligencePrivateStorage::disk(
                'intelligence.vehicle_plate_hybrid_review.disk',
            );
            $stored = $disk->put(
                $storedPath,
                $sanitized->contents,
                ['visibility' => 'private'],
            );
        } catch (Throwable) {
            throw new VehiclePlateRuntimeUnavailableException;
        }
        unset($sanitized);
        if (! $stored) {
            throw new VehiclePlateRuntimeUnavailableException;
        }

        try {
            $run = DB::transaction(function () use (
                $vehicle,
                $agencyId,
                $actor,
                $runId,
                $mime,
                $extension,
                $bytes,
                $width,
                $height,
                $sha256,
                $storedPath,
                $inputKind,
                $detectorModelName,
                $detectorSha256,
                $detectorThreshold,
                $detectorPadding,
            ): VehiclePlatePredictionRun {
                if ($vehicle !== null) {
                    DB::selectOne(
                        'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
                        ['vehicle-plate-hybrid|'.$vehicle->tenant_id.'|'.$vehicle->id],
                    );
                    $this->recoverStaleRuns($vehicle);
                    if (VehiclePlatePredictionRun::query()
                        ->where('vehicle_id', $vehicle->id)
                        ->whereIn('status', [
                            VehiclePlatePredictionStatus::Queued->value,
                            VehiclePlatePredictionStatus::Running->value,
                        ])->exists()) {
                        throw new VehiclePlatePredictionAlreadyActiveException;
                    }
                }

                $run = VehiclePlatePredictionRun::create([
                    'agency_id' => $agencyId,
                    'run_id' => $runId,
                    'vehicle_id' => $vehicle?->id,
                    'requested_by' => $actor->id,
                    'status' => VehiclePlatePredictionStatus::Queued,
                    'input_kind' => $inputKind,
                    'input_mime' => $mime,
                    'input_extension' => $extension,
                    'input_bytes' => $bytes,
                    'input_width' => $width,
                    'input_height' => $height,
                    'input_sha256' => $sha256,
                    'input_stored_path' => $storedPath,
                    'detector_model_name' => $detectorModelName,
                    'detector_checkpoint_sha256' => $detectorSha256,
                    'detector_threshold' => $detectorThreshold,
                    'detector_padding_ratio' => $detectorPadding,
                    'model_name' => VehiclePlateHybridContract::MODEL_NAME,
                    'result_schema_version' => VehiclePlateHybridContract::RESULT_SCHEMA_VERSION,
                    'fallback_version' => VehiclePlateHybridContract::FALLBACK_VERSION,
                    'operational_effect' => VehiclePlateHybridContract::OPERATIONAL_EFFECT,
                    'requested_at' => now(),
                ]);

                $this->audit->record('prediction.vehicle_plate.run_queued', $run, [], [
                    'run_id' => $run->run_id,
                    'vehicle_id' => $run->vehicle_id,
                    'status' => VehiclePlatePredictionStatus::Queued->value,
                    'input_kind' => $run->input_kind,
                    'input_mime' => $run->input_mime,
                    'input_bytes' => $run->input_bytes,
                    'detector_required' => $run->usesDetector(),
                    'effect' => VehiclePlateHybridContract::OPERATIONAL_EFFECT,
                ]);

                return $run;
            }, 3);
        } catch (Throwable $exception) {
            IntelligencePrivateStorage::deleteAfterFailure(
                'intelligence.vehicle_plate_hybrid_review.disk',
                $storedPath,
            );

            throw $exception;
        }

        try {
            RunVehiclePlatePrediction::dispatch($run->run_id, $run->tenant_id, $actor->id)
                ->onQueue((string) config('intelligence.vehicle_plate_hybrid_review.runtime_queue'))
                ->afterCommit();
        } catch (Throwable) {
            $updated = 0;
            try {
                $updated = DB::table('vehicle_plate_prediction_runs')
                    ->where('tenant_id', $run->tenant_id)
                    ->where('run_id', $run->run_id)
                    ->where('status', VehiclePlatePredictionStatus::Queued->value)
                    ->update([
                        'status' => VehiclePlatePredictionStatus::Failed->value,
                        'failure_code' => 'QUEUE_DISPATCH_FAILED',
                        'started_at' => now(),
                        'finished_at' => now(),
                    ]);
            } catch (Throwable) {
                // La requête HTTP reste fermée même si la base est devenue indisponible.
            }
            IntelligencePrivateStorage::deleteAfterFailure(
                'intelligence.vehicle_plate_hybrid_review.disk',
                $storedPath,
            );
            try {
                if ($updated !== 1) {
                    throw new \RuntimeException('queue_failure_not_persisted');
                }
                $this->audit->record('prediction.vehicle_plate.run_failed', $run, [], [
                    'run_id' => $run->run_id,
                    'failure_code' => 'QUEUE_DISPATCH_FAILED',
                    'effect' => VehiclePlateHybridContract::OPERATIONAL_EFFECT,
                ]);
            } catch (Throwable) {
                // Le statut persistant suffit si l’audit secondaire est indisponible.
            }

            throw new VehiclePlateRuntimeUnavailableException;
        }

        return $run;
    }

    private function assertAllowed(Vehicle $vehicle, User $actor): void
    {
        $contextAgency = $this->context->agencyId();
        if ($actor->tenant_id !== $vehicle->tenant_id
            || $this->context->tenantId() !== $vehicle->tenant_id
            || ! $actor->is_active
            || ! $actor->hasPermission('prediction.view')
            || ! $actor->hasPermission('prediction.plate.review')
            || ($actor->agency_id !== null && $actor->agency_id !== $vehicle->agency_id)
            || ($contextAgency !== null && $contextAgency !== $vehicle->agency_id)) {
            throw new AuthorizationException;
        }
    }

    private function assertPreparationAllowed(User $actor): void
    {
        if ($actor->tenant_id !== $this->context->tenantId()
            || ! $actor->is_active
            || ! $actor->hasPermission('vehicle.create')) {
            throw new AuthorizationException;
        }
    }

    private function recoverStaleRuns(Vehicle $vehicle): void
    {
        $staleAfterSeconds = (int) config(
            'intelligence.vehicle_plate_hybrid_review.runtime_stale_after_seconds',
        );
        if ($staleAfterSeconds < 60) {
            return;
        }

        $cutoff = now()->subSeconds($staleAfterSeconds);
        $runs = VehiclePlatePredictionRun::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('status', [
                VehiclePlatePredictionStatus::Queued->value,
                VehiclePlatePredictionStatus::Running->value,
            ])
            ->where(function ($query) use ($cutoff): void {
                $query->where(function ($queued) use ($cutoff): void {
                    $queued->where('status', VehiclePlatePredictionStatus::Queued->value)
                        ->where('requested_at', '<', $cutoff);
                })->orWhere(function ($running) use ($cutoff): void {
                    $running->where('status', VehiclePlatePredictionStatus::Running->value)
                        ->where('started_at', '<', $cutoff);
                });
            })
            ->lockForUpdate()
            ->get();

        foreach ($runs as $run) {
            $run->forceFill([
                'status' => VehiclePlatePredictionStatus::Failed,
                'failure_code' => 'RUN_STALE_RECOVERED',
                'started_at' => $run->started_at ?? $run->requested_at,
                'finished_at' => now(),
            ])->save();
            $this->audit->record('prediction.vehicle_plate.run_failed', $run, [], [
                'run_id' => $run->run_id,
                'failure_code' => 'RUN_STALE_RECOVERED',
                'effect' => VehiclePlateHybridContract::OPERATIONAL_EFFECT,
            ]);
        }
    }
}
