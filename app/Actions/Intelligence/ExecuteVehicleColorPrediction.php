<?php

namespace App\Actions\Intelligence;

use App\Enums\IntelligenceCapability;
use App\Enums\VehicleColorPredictionStatus;
use App\Exceptions\VehicleColorExecutionException;
use App\Models\User;
use App\Models\VehicleColorPredictionRun;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\IntelligencePrivateStorage;
use App\Support\Intelligence\TenantIntelligenceAccess;
use App\Support\Intelligence\VehicleColor\VehicleColorContract;
use App\Support\Intelligence\VehicleColor\VehicleColorInputArtifact;
use App\Support\Intelligence\VehicleColor\VehicleColorModelArtifact;
use App\Support\Intelligence\VehicleColor\VehicleColorResultValidator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Throwable;

final class ExecuteVehicleColorPrediction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantIntelligenceAccess $tenantAccess,
        private readonly VehicleColorModelArtifact $modelArtifact,
        private readonly VehicleColorInputArtifact $inputArtifact,
        private readonly VehicleColorResultValidator $resultValidator,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(string $runId, int $tenantId, int $actorId): void
    {
        $this->context->run($tenantId, function () use ($runId, $tenantId, $actorId): void {
            if (! $this->tenantAccess->usable(IntelligenceCapability::VehicleColor, $tenantId)) {
                throw new VehicleColorExecutionException('TENANT_INTELLIGENCE_UNAVAILABLE');
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
                    && $actor->hasPermission('prediction.color.review')));
            if ($actor === null
                || ! $actorCanExecute
                || ($actor->agency_id !== null && $actor->agency_id !== $run->agency_id)) {
                throw new VehicleColorExecutionException('RUN_ACTOR_NOT_AUTHORIZED');
            }
            if (! $preparatory
                && ($run->vehicle === null || $run->vehicle->agency_id !== $run->agency_id)) {
                throw new VehicleColorExecutionException('VEHICLE_UNAVAILABLE');
            }
            if (! $this->modelArtifact->configuredIsValid()) {
                throw new VehicleColorExecutionException('MODEL_ARTIFACT_INVALID');
            }
            if (! $this->inputArtifact->valid($run)) {
                throw new VehicleColorExecutionException('INPUT_ARTIFACT_INVALID');
            }

            $output = $this->executeProcess($run);
            $validated = $this->resultValidator->validate($output, $run);

            DB::transaction(function () use ($run, $validated): VehicleColorPredictionRun {
                $candidate = VehicleColorPredictionRun::query()
                    ->where('run_id', $run->run_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($candidate->status !== VehicleColorPredictionStatus::Running) {
                    throw new VehicleColorExecutionException('RUN_STATE_CONFLICT');
                }

                $candidate->forceFill([
                    'status' => VehicleColorPredictionStatus::Succeeded,
                    'suggested_color' => $validated->suggestedColor,
                    'confidence' => $validated->confidence,
                    'model_accepted' => $validated->modelAccepted,
                    'probabilities' => $validated->probabilities,
                    'finished_at' => now(),
                ])->save();

                $this->audit->record('prediction.vehicle_color.run_succeeded', $candidate, [], [
                    'run_id' => $candidate->run_id,
                    'vehicle_id' => $candidate->vehicle_id,
                    'suggested_color' => $candidate->suggested_color,
                    'confidence' => (float) $candidate->confidence,
                    'model_accepted' => $candidate->model_accepted,
                    'human_validation_required' => true,
                    'effect' => VehicleColorContract::OPERATIONAL_EFFECT,
                ]);

                return $candidate;
            }, 3);
        });
    }

    private function markRunning(string $runId, int $actorId): VehicleColorPredictionRun
    {
        return DB::transaction(function () use ($runId, $actorId): VehicleColorPredictionRun {
            $run = VehicleColorPredictionRun::query()
                ->with('vehicle')
                ->where('run_id', $runId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($run->requested_by !== $actorId || $run->status !== VehicleColorPredictionStatus::Queued) {
                throw new VehicleColorExecutionException('RUN_STATE_CONFLICT');
            }

            $run->forceFill([
                'status' => VehicleColorPredictionStatus::Running,
                'started_at' => now(),
            ])->save();

            return $run;
        }, 3);
    }

    private function executeProcess(VehicleColorPredictionRun $run): string
    {
        $binary = (string) config('intelligence.vehicle_color_v8.python_binary');
        $script = (string) config('intelligence.vehicle_color_v8.runtime_script');
        $provider = (string) config('intelligence.vehicle_color_v8.execution_provider');
        $timeout = (int) config('intelligence.vehicle_color_v8.runtime_timeout_seconds');
        if ($binary === ''
            || $script === ''
            || ! is_file($script)
            || ! in_array($provider, ['CPUExecutionProvider', 'CUDAExecutionProvider'], true)
            || $timeout < 1
            || $timeout > 30) {
            throw new VehicleColorExecutionException('RUNTIME_CONFIGURATION_INVALID');
        }

        try {
            $image = IntelligencePrivateStorage::path(
                'intelligence.vehicle_color_v8.disk',
                (string) $run->input_stored_path,
            );
            $result = Process::path(sys_get_temp_dir())
                ->timeout($timeout)
                ->env([
                    'PYTHONDONTWRITEBYTECODE' => '1',
                    'PYTHONHASHSEED' => '20260822',
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
                ])
                ->run([
                    $binary,
                    $script,
                    '--image',
                    $image,
                    '--model',
                    $this->modelArtifact->configuredModelPath(),
                    '--metadata',
                    $this->modelArtifact->configuredMetadataPath(),
                    '--provider',
                    $provider,
                    '--stdout',
                ]);
        } catch (ProcessTimedOutException) {
            throw new VehicleColorExecutionException('COLOR_PROCESS_TIMEOUT');
        } catch (VehicleColorExecutionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new VehicleColorExecutionException('COLOR_PROCESS_START_FAILED');
        }

        if ($result->failed()) {
            throw new VehicleColorExecutionException('COLOR_PROCESS_FAILED');
        }
        $output = $result->output();
        if ($output === '' || strlen($output) > 65536) {
            throw new VehicleColorExecutionException('COLOR_OUTPUT_INVALID');
        }

        return $output;
    }
}
