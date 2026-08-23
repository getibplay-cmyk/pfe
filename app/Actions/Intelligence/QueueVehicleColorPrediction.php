<?php

namespace App\Actions\Intelligence;

use App\Enums\VehicleColorPredictionStatus;
use App\Exceptions\VehicleColorPredictionAlreadyActiveException;
use App\Exceptions\VehicleColorRuntimeUnavailableException;
use App\Jobs\RunVehicleColorPrediction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleColorPredictionRun;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\VehicleColor\VehicleColorContract;
use App\Support\Intelligence\VehicleColor\VehicleColorModelArtifact;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class QueueVehicleColorPrediction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly VehicleColorModelArtifact $modelArtifact,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(Vehicle $vehicle, UploadedFile $image, User $actor): VehicleColorPredictionRun
    {
        $this->assertAllowed($vehicle, $actor);
        if (! $this->runtimeReady()) {
            throw new VehicleColorRuntimeUnavailableException;
        }

        $runId = (string) Str::uuid();
        $mime = (string) $image->getMimeType();
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new VehicleColorRuntimeUnavailableException,
        };
        $sourcePath = $image->getRealPath();
        $bytes = $image->getSize();
        $sha256 = is_string($sourcePath) ? hash_file('sha256', $sourcePath) : false;
        if (! is_int($bytes) || $bytes < 1 || ! is_string($sha256)) {
            throw new VehicleColorRuntimeUnavailableException;
        }

        $disk = Storage::disk((string) config('intelligence.vehicle_color_v8.disk'));
        $directory = 'intelligence/color-v8/inputs/'.$this->context->tenantId();
        $storedPath = $disk->putFileAs($directory, $image, $runId.'.'.$extension);
        if (! is_string($storedPath)) {
            throw new VehicleColorRuntimeUnavailableException;
        }

        try {
            $run = DB::transaction(function () use (
                $vehicle,
                $actor,
                $runId,
                $mime,
                $extension,
                $bytes,
                $sha256,
                $storedPath,
            ): VehicleColorPredictionRun {
                DB::selectOne(
                    'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
                    ['vehicle-color-v8|'.$vehicle->tenant_id.'|'.$vehicle->id],
                );
                $this->recoverStaleRuns($vehicle);
                if (VehicleColorPredictionRun::query()
                    ->where('vehicle_id', $vehicle->id)
                    ->whereIn('status', [
                        VehicleColorPredictionStatus::Queued->value,
                        VehicleColorPredictionStatus::Running->value,
                    ])->exists()) {
                    throw new VehicleColorPredictionAlreadyActiveException;
                }

                $run = VehicleColorPredictionRun::create([
                    'agency_id' => $vehicle->agency_id,
                    'run_id' => $runId,
                    'vehicle_id' => $vehicle->id,
                    'requested_by' => $actor->id,
                    'status' => VehicleColorPredictionStatus::Queued,
                    'input_mime' => $mime,
                    'input_extension' => $extension,
                    'input_bytes' => $bytes,
                    'input_sha256' => $sha256,
                    'input_stored_path' => $storedPath,
                    'model_name' => VehicleColorContract::MODEL_NAME,
                    'model_version' => VehicleColorContract::MODEL_VERSION,
                    'model_artifact_sha256' => VehicleColorContract::MODEL_ARTIFACT_SHA256,
                    'metadata_sha256' => VehicleColorContract::METADATA_SHA256,
                    'accepted_threshold' => VehicleColorContract::ACCEPTED_THRESHOLD,
                    'operational_effect' => VehicleColorContract::OPERATIONAL_EFFECT,
                    'requested_at' => now(),
                ]);

                $this->audit->record('prediction.vehicle_color.run_queued', $run, [], [
                    'run_id' => $run->run_id,
                    'vehicle_id' => $run->vehicle_id,
                    'status' => VehicleColorPredictionStatus::Queued->value,
                    'input_mime' => $run->input_mime,
                    'input_bytes' => $run->input_bytes,
                    'effect' => VehicleColorContract::OPERATIONAL_EFFECT,
                ]);

                return $run;
            }, 3);
        } catch (Throwable $exception) {
            $disk->delete($storedPath);

            throw $exception;
        }

        try {
            RunVehicleColorPrediction::dispatch($run->run_id, $run->tenant_id, $actor->id)
                ->onQueue((string) config('intelligence.vehicle_color_v8.runtime_queue'));
        } catch (Throwable) {
            $updated = 0;
            try {
                $updated = DB::table('vehicle_color_prediction_runs')
                    ->where('tenant_id', $run->tenant_id)
                    ->where('run_id', $run->run_id)
                    ->where('status', VehicleColorPredictionStatus::Queued->value)
                    ->update([
                        'status' => VehicleColorPredictionStatus::Failed->value,
                        'failure_code' => 'QUEUE_DISPATCH_FAILED',
                        'started_at' => now(),
                        'finished_at' => now(),
                    ]);
            } catch (Throwable) {
                // La requête HTTP reste fermée même si la base est devenue indisponible.
            }
            $disk->delete($storedPath);
            try {
                if ($updated !== 1) {
                    throw new \RuntimeException('queue_failure_not_persisted');
                }
                $this->audit->record('prediction.vehicle_color.run_failed', $run, [], [
                    'run_id' => $run->run_id,
                    'failure_code' => 'QUEUE_DISPATCH_FAILED',
                    'effect' => VehicleColorContract::OPERATIONAL_EFFECT,
                ]);
            } catch (Throwable) {
                // Le statut persistant suffit si l’audit secondaire est indisponible.
            }

            throw new VehicleColorRuntimeUnavailableException;
        }

        return $run;
    }

    private function assertAllowed(Vehicle $vehicle, User $actor): void
    {
        $contextAgency = $this->context->agencyId();
        if (! (bool) config('intelligence.vehicle_color_v8.enabled')
            || $actor->tenant_id !== $vehicle->tenant_id
            || $this->context->tenantId() !== $vehicle->tenant_id
            || ! $actor->is_active
            || ! $actor->hasPermission('prediction.color.review')
            || ($actor->agency_id !== null && $actor->agency_id !== $vehicle->agency_id)
            || ($contextAgency !== null && $contextAgency !== $vehicle->agency_id)) {
            throw new AuthorizationException;
        }
    }

    private function runtimeReady(): bool
    {
        $provider = (string) config('intelligence.vehicle_color_v8.execution_provider');
        $timeout = (int) config('intelligence.vehicle_color_v8.runtime_timeout_seconds');

        return $this->modelArtifact->configuredIsValid()
            && (string) config('intelligence.vehicle_color_v8.python_binary') !== ''
            && is_file((string) config('intelligence.vehicle_color_v8.runtime_script'))
            && in_array($provider, ['CPUExecutionProvider', 'CUDAExecutionProvider'], true)
            && $timeout >= 1
            && $timeout <= 30;
    }

    private function recoverStaleRuns(Vehicle $vehicle): void
    {
        $staleAfterSeconds = (int) config('intelligence.vehicle_color_v8.runtime_stale_after_seconds');
        if ($staleAfterSeconds < 60) {
            return;
        }

        $cutoff = now()->subSeconds($staleAfterSeconds);
        $runs = VehicleColorPredictionRun::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('status', [
                VehicleColorPredictionStatus::Queued->value,
                VehicleColorPredictionStatus::Running->value,
            ])
            ->where(function ($query) use ($cutoff): void {
                $query->where(function ($queued) use ($cutoff): void {
                    $queued->where('status', VehicleColorPredictionStatus::Queued->value)
                        ->where('requested_at', '<', $cutoff);
                })->orWhere(function ($running) use ($cutoff): void {
                    $running->where('status', VehicleColorPredictionStatus::Running->value)
                        ->where('started_at', '<', $cutoff);
                });
            })
            ->lockForUpdate()
            ->get();

        foreach ($runs as $run) {
            $run->forceFill([
                'status' => VehicleColorPredictionStatus::Failed,
                'failure_code' => 'RUN_STALE_RECOVERED',
                'started_at' => $run->started_at ?? $run->requested_at,
                'finished_at' => now(),
            ])->save();
            $this->audit->record('prediction.vehicle_color.run_failed', $run, [], [
                'run_id' => $run->run_id,
                'failure_code' => 'RUN_STALE_RECOVERED',
                'effect' => VehicleColorContract::OPERATIONAL_EFFECT,
            ]);
        }
    }
}
