<?php

namespace App\Actions\Intelligence;

use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Enums\RentalContractStatus;
use App\Enums\VehicleDamagePredictionStatus;
use App\Exceptions\VehicleDamagePredictionAlreadyActiveException;
use App\Exceptions\VehicleDamageRuntimeUnavailableException;
use App\Jobs\RunVehicleDamagePrediction;
use App\Models\RentalContract;
use App\Models\User;
use App\Models\VehicleDamagePredictionRun;
use App\Models\VehicleInspection;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\IntelligencePrivateStorage;
use App\Support\Intelligence\VehicleDamage\VehicleDamageContract;
use App\Support\Intelligence\VehicleDamage\VehicleDamageImageSanitizer;
use App\Support\Intelligence\VehicleDamage\VehicleDamageModelArtifact;
use App\Support\Intelligence\VehicleDamage\VehicleDamageRuntimeReadiness;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class QueueVehicleDamagePrediction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly VehicleDamageModelArtifact $modelArtifact,
        private readonly VehicleDamageImageSanitizer $imageSanitizer,
        private readonly VehicleDamageRuntimeReadiness $readiness,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(
        VehicleInspection $inspection,
        UploadedFile $image,
        User $actor,
    ): VehicleDamagePredictionRun {
        $this->assertAllowed($inspection, $actor);

        return $this->queue(
            $inspection->rentalContract()->firstOrFail(),
            $inspection,
            $image,
            $actor,
        );
    }

    public function handlePreparation(
        RentalContract $contract,
        UploadedFile $image,
        User $actor,
    ): VehicleDamagePredictionRun {
        $this->assertPreparationAllowed($contract, $actor);

        return $this->queue($contract, null, $image, $actor);
    }

    private function queue(
        RentalContract $contract,
        ?VehicleInspection $inspection,
        UploadedFile $image,
        User $actor,
    ): VehicleDamagePredictionRun {
        if (! $this->readiness->ready()) {
            throw new VehicleDamageRuntimeUnavailableException;
        }

        $sanitized = $this->imageSanitizer->sanitize($image);
        $runId = (string) Str::uuid();
        $directory = 'intelligence/vehicle-damage/inputs/'.$this->context->tenantId();
        $storedPath = $directory.'/'.$runId.'.jpg';
        try {
            $disk = IntelligencePrivateStorage::disk('intelligence.vehicle_damage_v1.disk');
            $stored = $disk->put(
                $storedPath,
                $sanitized->contents,
                ['visibility' => 'private'],
            );
        } catch (Throwable) {
            throw new VehicleDamageRuntimeUnavailableException;
        }
        if (! $stored) {
            throw new VehicleDamageRuntimeUnavailableException;
        }

        try {
            $run = DB::transaction(function () use (
                $contract,
                $inspection,
                $actor,
                $runId,
                $storedPath,
                $sanitized,
            ): VehicleDamagePredictionRun {
                $lockedContract = RentalContract::query()
                    ->with('vehicle')
                    ->lockForUpdate()
                    ->findOrFail($contract->id);
                $lockedInspection = null;
                if ($inspection === null) {
                    $this->assertPreparationAllowed($lockedContract, $actor);
                } else {
                    DB::selectOne(
                        'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
                        ['vehicle-damage-v1|'.$inspection->tenant_id.'|'.$inspection->id],
                    );
                    $lockedInspection = VehicleInspection::query()
                        ->with('vehicle')
                        ->lockForUpdate()
                        ->findOrFail($inspection->id);
                    $this->assertReturnInspection($lockedInspection);
                    if ($lockedInspection->rental_contract_id !== $lockedContract->id) {
                        throw new AuthorizationException;
                    }
                    $this->recoverStaleRuns($lockedInspection);
                    if (VehicleDamagePredictionRun::query()
                        ->where('vehicle_inspection_id', $lockedInspection->id)
                        ->whereIn('status', [
                            VehicleDamagePredictionStatus::Queued->value,
                            VehicleDamagePredictionStatus::Running->value,
                        ])->exists()) {
                        throw new VehicleDamagePredictionAlreadyActiveException;
                    }
                }

                $run = VehicleDamagePredictionRun::create([
                    'agency_id' => $lockedContract->agency_id,
                    'rental_contract_id' => $lockedContract->id,
                    'run_id' => $runId,
                    'vehicle_inspection_id' => $lockedInspection?->id,
                    'vehicle_id' => $lockedContract->vehicle_id,
                    'requested_by' => $actor->id,
                    'status' => VehicleDamagePredictionStatus::Queued,
                    'input_mime' => $sanitized->mime,
                    'input_extension' => $sanitized->extension,
                    'input_bytes' => $sanitized->bytes,
                    'input_sha256' => $sanitized->sha256,
                    'input_stored_path' => $storedPath,
                    'input_width' => $sanitized->width,
                    'input_height' => $sanitized->height,
                    'model_name' => VehicleDamageContract::modelName(),
                    'model_version' => VehicleDamageContract::modelVersion(),
                    'model_artifact_sha256' => $this->modelArtifact->configuredModelSha256(),
                    'model_card_sha256' => $this->modelArtifact->configuredModelCardSha256(),
                    'decision_threshold' => VehicleDamageContract::decisionThreshold(),
                    'operational_effect' => VehicleDamageContract::OPERATIONAL_EFFECT,
                    'requested_at' => now(),
                ]);

                $this->audit->record('prediction.vehicle_damage.run_queued', $run, [], [
                    'run_id' => $run->run_id,
                    'rental_contract_id' => $run->rental_contract_id,
                    'vehicle_id' => $run->vehicle_id,
                    'vehicle_inspection_id' => $run->vehicle_inspection_id,
                    'status' => VehicleDamagePredictionStatus::Queued->value,
                    'input_mime' => $run->input_mime,
                    'input_bytes' => $run->input_bytes,
                    'effect' => VehicleDamageContract::OPERATIONAL_EFFECT,
                ]);

                return $run;
            }, 3);
        } catch (Throwable $exception) {
            IntelligencePrivateStorage::deleteAfterFailure(
                'intelligence.vehicle_damage_v1.disk',
                $storedPath,
            );

            throw $exception;
        }
        unset($sanitized);

        try {
            RunVehicleDamagePrediction::dispatch($run->run_id, $run->tenant_id, $actor->id)
                ->onQueue((string) config('intelligence.vehicle_damage_v1.runtime_queue'))
                ->afterCommit();
        } catch (Throwable) {
            $updated = 0;
            try {
                $updated = DB::table('vehicle_damage_prediction_runs')
                    ->where('tenant_id', $run->tenant_id)
                    ->where('run_id', $run->run_id)
                    ->where('status', VehicleDamagePredictionStatus::Queued->value)
                    ->update([
                        'status' => VehicleDamagePredictionStatus::Failed->value,
                        'failure_code' => 'QUEUE_DISPATCH_FAILED',
                        'started_at' => now(),
                        'finished_at' => now(),
                    ]);
            } catch (Throwable) {
                // La requête HTTP reste fermée même si la base est devenue indisponible.
            }
            IntelligencePrivateStorage::deleteAfterFailure(
                'intelligence.vehicle_damage_v1.disk',
                $storedPath,
            );
            try {
                if ($updated !== 1) {
                    throw new \RuntimeException('queue_failure_not_persisted');
                }
                $this->audit->record('prediction.vehicle_damage.run_failed', $run, [], [
                    'run_id' => $run->run_id,
                    'failure_code' => 'QUEUE_DISPATCH_FAILED',
                    'effect' => VehicleDamageContract::OPERATIONAL_EFFECT,
                ]);
            } catch (Throwable) {
                // Le statut persistant suffit si l’audit secondaire est indisponible.
            }

            throw new VehicleDamageRuntimeUnavailableException;
        }

        return $run;
    }

    private function assertAllowed(VehicleInspection $inspection, User $actor): void
    {
        $contextAgency = $this->context->agencyId();
        if (! (bool) config('intelligence.vehicle_damage_v1.enabled')
            || $actor->tenant_id !== $inspection->tenant_id
            || $this->context->tenantId() !== $inspection->tenant_id
            || ! $actor->is_active
            || ! $actor->hasPermission('prediction.view')
            || ! $actor->hasPermission('prediction.damage.review')
            || ($actor->agency_id !== null && $actor->agency_id !== $inspection->agency_id)
            || ($contextAgency !== null && $contextAgency !== $inspection->agency_id)) {
            throw new AuthorizationException;
        }
        $this->assertReturnInspection($inspection);
    }

    private function assertPreparationAllowed(RentalContract $contract, User $actor): void
    {
        $contextAgency = $this->context->agencyId();
        if (! (bool) config('intelligence.vehicle_damage_v1.enabled')
            || $contract->status !== RentalContractStatus::Active
            || $contract->vehicle === null
            || $contract->vehicle->agency_id !== $contract->agency_id
            || $actor->tenant_id !== $contract->tenant_id
            || $this->context->tenantId() !== $contract->tenant_id
            || ! $actor->is_active
            || ! $actor->hasPermission('contract.return')
            || ! $actor->hasPermission('inspection.manage')
            || ! $actor->hasPermission('prediction.view')
            || ! $actor->hasPermission('prediction.damage.review')
            || ($actor->agency_id !== null && $actor->agency_id !== $contract->agency_id)
            || ($contextAgency !== null && $contextAgency !== $contract->agency_id)) {
            throw new AuthorizationException;
        }
    }

    private function assertReturnInspection(VehicleInspection $inspection): void
    {
        if ($inspection->inspection_type !== InspectionType::Return
            || $inspection->status !== InspectionStatus::Completed
            || $inspection->vehicle === null
            || $inspection->vehicle->agency_id !== $inspection->agency_id) {
            throw new AuthorizationException;
        }
    }

    private function recoverStaleRuns(VehicleInspection $inspection): void
    {
        $staleAfterSeconds = (int) config('intelligence.vehicle_damage_v1.runtime_stale_after_seconds');
        if ($staleAfterSeconds < 120) {
            return;
        }

        $cutoff = now()->subSeconds($staleAfterSeconds);
        $runs = VehicleDamagePredictionRun::query()
            ->where('vehicle_inspection_id', $inspection->id)
            ->whereIn('status', [
                VehicleDamagePredictionStatus::Queued->value,
                VehicleDamagePredictionStatus::Running->value,
            ])
            ->where(function ($query) use ($cutoff): void {
                $query->where(function ($queued) use ($cutoff): void {
                    $queued->where('status', VehicleDamagePredictionStatus::Queued->value)
                        ->where('requested_at', '<', $cutoff);
                })->orWhere(function ($running) use ($cutoff): void {
                    $running->where('status', VehicleDamagePredictionStatus::Running->value)
                        ->where('started_at', '<', $cutoff);
                });
            })
            ->lockForUpdate()
            ->get();

        foreach ($runs as $run) {
            $run->forceFill([
                'status' => VehicleDamagePredictionStatus::Failed,
                'failure_code' => 'RUN_STALE_RECOVERED',
                'started_at' => $run->started_at ?? $run->requested_at,
                'finished_at' => now(),
            ])->save();
            $this->audit->record('prediction.vehicle_damage.run_failed', $run, [], [
                'run_id' => $run->run_id,
                'failure_code' => 'RUN_STALE_RECOVERED',
                'effect' => VehicleDamageContract::OPERATIONAL_EFFECT,
            ]);
        }
    }
}
