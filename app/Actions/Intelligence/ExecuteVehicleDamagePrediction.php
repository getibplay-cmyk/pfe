<?php

namespace App\Actions\Intelligence;

use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Enums\IntelligenceCapability;
use App\Enums\RentalContractStatus;
use App\Enums\VehicleDamagePredictionStatus;
use App\Exceptions\VehicleDamageExecutionException;
use App\Models\User;
use App\Models\VehicleDamagePredictionRun;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\IntelligencePrivateStorage;
use App\Support\Intelligence\TenantIntelligenceAccess;
use App\Support\Intelligence\VehicleDamage\VehicleDamageContract;
use App\Support\Intelligence\VehicleDamage\VehicleDamageInputArtifact;
use App\Support\Intelligence\VehicleDamage\VehicleDamageModelArtifact;
use App\Support\Intelligence\VehicleDamage\VehicleDamageResultValidator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Throwable;

final class ExecuteVehicleDamagePrediction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantIntelligenceAccess $tenantAccess,
        private readonly VehicleDamageModelArtifact $modelArtifact,
        private readonly VehicleDamageInputArtifact $inputArtifact,
        private readonly VehicleDamageResultValidator $resultValidator,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(string $runId, int $tenantId, int $actorId): void
    {
        $this->context->run($tenantId, function () use ($runId, $tenantId, $actorId): void {
            if (! $this->tenantAccess->usable(IntelligenceCapability::VehicleDamage, $tenantId)) {
                throw new VehicleDamageExecutionException('TENANT_INTELLIGENCE_UNAVAILABLE');
            }
            $run = $this->markRunning($runId, $actorId);
            $actor = User::query()
                ->whereKey($actorId)
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->first();
            if ($actor === null
                || ! $actor->hasPermission('prediction.view')
                || ! $actor->hasPermission('prediction.damage.review')
                || ($actor->agency_id !== null && $actor->agency_id !== $run->agency_id)) {
                throw new VehicleDamageExecutionException('RUN_ACTOR_NOT_AUTHORIZED');
            }
            $inspection = $run->inspection;
            $contract = $run->rentalContract;
            if ($contract === null
                || $contract->id !== $run->rental_contract_id
                || $contract->vehicle_id !== $run->vehicle_id
                || $contract->agency_id !== $run->agency_id
                || ($inspection === null && $contract->status !== RentalContractStatus::Active)
                || ($inspection !== null && (
                    $inspection->inspection_type !== InspectionType::Return
                    || $inspection->status !== InspectionStatus::Completed
                    || $inspection->rental_contract_id !== $contract->id
                    || $inspection->vehicle_id !== $run->vehicle_id
                    || $inspection->agency_id !== $run->agency_id
                ))) {
                throw new VehicleDamageExecutionException('RETURN_INSPECTION_UNAVAILABLE');
            }
            if ($run->model_name !== VehicleDamageContract::modelName()
                || $run->model_version !== VehicleDamageContract::modelVersion()
                || abs((float) $run->decision_threshold - VehicleDamageContract::decisionThreshold()) > 0.000001) {
                throw new VehicleDamageExecutionException('MODEL_BACKEND_MISMATCH');
            }
            if (! $this->modelArtifact->configuredIsValid()
                || ! hash_equals($run->model_artifact_sha256, $this->modelArtifact->configuredModelSha256())
                || ! hash_equals($run->model_card_sha256, $this->modelArtifact->configuredModelCardSha256())) {
                throw new VehicleDamageExecutionException('MODEL_ARTIFACT_INVALID');
            }
            if (! $this->inputArtifact->valid($run)) {
                throw new VehicleDamageExecutionException('INPUT_ARTIFACT_INVALID');
            }

            $output = $this->executeProcess($run);
            $validated = $this->resultValidator->validate($output, $run);

            DB::transaction(function () use ($run, $validated): void {
                $candidate = VehicleDamagePredictionRun::query()
                    ->where('run_id', $run->run_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($candidate->status !== VehicleDamagePredictionStatus::Running) {
                    throw new VehicleDamageExecutionException('RUN_STATE_CONFLICT');
                }

                $candidate->forceFill([
                    'status' => VehicleDamagePredictionStatus::Succeeded,
                    'quality_status' => $validated->qualityStatus,
                    'quality_reasons' => $validated->qualityReasons,
                    'quality_metrics' => $validated->qualityMetrics,
                    'evaluated_patches' => $validated->evaluatedPatches,
                    'max_probability_damage' => $validated->maxProbabilityDamage,
                    'suggested_damage' => $validated->suggestedDamage,
                    'candidate_regions' => $validated->candidateRegions,
                    'finished_at' => now(),
                ])->save();

                $this->audit->record('prediction.vehicle_damage.run_succeeded', $candidate, [], [
                    'run_id' => $candidate->run_id,
                    'vehicle_id' => $candidate->vehicle_id,
                    'vehicle_inspection_id' => $candidate->vehicle_inspection_id,
                    'quality_status' => $candidate->quality_status,
                    'suggested_damage' => $candidate->suggested_damage,
                    'max_probability_damage' => $candidate->max_probability_damage === null
                        ? null
                        : (float) $candidate->max_probability_damage,
                    'candidate_count' => count($candidate->candidate_regions ?? []),
                    'human_validation_required' => true,
                    'effect' => VehicleDamageContract::OPERATIONAL_EFFECT,
                ]);
            }, 3);
        });
    }

    private function markRunning(string $runId, int $actorId): VehicleDamagePredictionRun
    {
        return DB::transaction(function () use ($runId, $actorId): VehicleDamagePredictionRun {
            $run = VehicleDamagePredictionRun::query()
                ->with(['inspection', 'rentalContract.vehicle'])
                ->where('run_id', $runId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($run->requested_by !== $actorId || $run->status !== VehicleDamagePredictionStatus::Queued) {
                throw new VehicleDamageExecutionException('RUN_STATE_CONFLICT');
            }

            $run->forceFill([
                'status' => VehicleDamagePredictionStatus::Running,
                'started_at' => now(),
            ])->save();

            return $run;
        }, 3);
    }

    private function executeProcess(VehicleDamagePredictionRun $run): string
    {
        $binary = (string) config('intelligence.vehicle_damage_v1.python_binary');
        $script = (string) config('intelligence.vehicle_damage_v1.runtime_script');
        $provider = (string) config('intelligence.vehicle_damage_v1.execution_provider');
        $timeout = (int) config('intelligence.vehicle_damage_v1.runtime_timeout_seconds');
        $maxPatches = (int) config('intelligence.vehicle_damage_v1.max_scan_patches');
        if ($binary === ''
            || $script === ''
            || ! is_file($script)
            || ! in_array($provider, ['CPUExecutionProvider', 'CUDAExecutionProvider'], true)
            || $timeout < 10
            || $timeout > 120
            || $maxPatches < 1
            || $maxPatches > 64) {
            throw new VehicleDamageExecutionException('RUNTIME_CONFIGURATION_INVALID');
        }

        try {
            $image = IntelligencePrivateStorage::path(
                'intelligence.vehicle_damage_v1.disk',
                (string) $run->input_stored_path,
            );
            $result = Process::path(sys_get_temp_dir())
                ->timeout($timeout)
                ->env($this->closedEnvironment())
                ->run([
                    $binary,
                    $script,
                    '--run-id',
                    $run->run_id,
                    '--image',
                    $image,
                    '--model',
                    $this->modelArtifact->configuredModelPath(),
                    '--model-card',
                    $this->modelArtifact->configuredModelCardPath(),
                    '--model-sha256',
                    $run->model_artifact_sha256,
                    '--model-card-sha256',
                    $run->model_card_sha256,
                    '--input-sha256',
                    $run->input_sha256,
                    '--input-bytes',
                    (string) $run->input_bytes,
                    '--input-width',
                    (string) $run->input_width,
                    '--input-height',
                    (string) $run->input_height,
                    '--provider',
                    $provider,
                    '--max-patches',
                    (string) $maxPatches,
                    '--stdout',
                ]);
        } catch (ProcessTimedOutException) {
            throw new VehicleDamageExecutionException('DAMAGE_PROCESS_TIMEOUT');
        } catch (VehicleDamageExecutionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new VehicleDamageExecutionException('DAMAGE_PROCESS_START_FAILED');
        }

        if ($result->failed()) {
            throw new VehicleDamageExecutionException('DAMAGE_PROCESS_FAILED');
        }
        $output = $result->output();
        if ($output === '' || strlen($output) > 131_072) {
            throw new VehicleDamageExecutionException('DAMAGE_OUTPUT_INVALID');
        }

        return $output;
    }

    private function closedEnvironment(): array
    {
        return [
            'PYTHONDONTWRITEBYTECODE' => '1',
            'PYTHONHASHSEED' => '20260823',
            'ORT_DISABLE_TELEMETRY_EVENTS' => '1',
            'APP_KEY' => false,
            'DATABASE_URL' => false,
            'DB_URL' => false,
            'DB_USERNAME' => false,
            'DB_PASSWORD' => false,
            'REDIS_PASSWORD' => false,
            'MAIL_USERNAME' => false,
            'MAIL_PASSWORD' => false,
            'AWS_ACCESS_KEY_ID' => false,
            'AWS_SECRET_ACCESS_KEY' => false,
            'AWS_SESSION_TOKEN' => false,
            'INTELLIGENCE_EXPORT_HMAC_KEY' => false,
            'DEMO_PASSWORD' => false,
            'OPENAI_API_KEY' => false,
            'STRIPE_SECRET' => false,
            'PGPASSWORD' => false,
        ];
    }
}
