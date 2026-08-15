<?php

namespace App\Actions\Intelligence;

use App\Exceptions\DemandForecastIdempotencyConflictException;
use App\Exceptions\DemandForecastValidationException;
use App\Models\DemandForecast;
use App\Models\DemandForecastRun;
use App\Models\DemandHistoryExportRun;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\DemandForecasting\DemandForecastArtifactVerifier;
use App\Support\Intelligence\DemandForecasting\DemandForecastBatchValidator;
use App\Support\Intelligence\DemandForecasting\DemandForecastContract;
use App\Support\Intelligence\DemandForecasting\DemandForecastImportResult;
use App\Support\Intelligence\DemandForecasting\ValidatedDemandForecastBatch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ImportDemandForecastBatch
{
    public function __construct(
        private readonly DemandForecastBatchValidator $validator,
        private readonly DemandForecastArtifactVerifier $artifactVerifier,
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(
        DemandHistoryExportRun $history,
        UploadedFile $file,
        User $actor,
    ): DemandForecastImportResult {
        $this->assertActor($history, $actor);
        $maximumBytes = (int) config('intelligence.demand_forecasting.max_upload_kilobytes') * 1024;
        $uploadedBytes = $file->getSize();
        if (! is_int($uploadedBytes) || $uploadedBytes <= 0 || $uploadedBytes > $maximumBytes) {
            throw DemandForecastValidationException::at(
                '$',
                'taille du fichier JSON absente ou supérieure à la limite autorisée',
            );
        }
        $realPath = $file->getRealPath();
        $contents = is_string($realPath) ? file_get_contents($realPath) : false;
        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('Le lot de prévisions téléversé est vide ou illisible.');
        }

        return $this->handlePayload($history, $contents, $actor);
    }

    public function handlePayload(
        DemandHistoryExportRun $history,
        string $contents,
        User $actor,
    ): DemandForecastImportResult {
        $this->assertActor($history, $actor);
        $maximumBytes = (int) config('intelligence.demand_forecasting.max_upload_kilobytes') * 1024;
        if ($contents === '' || strlen($contents) > $maximumBytes) {
            throw DemandForecastValidationException::at(
                '$',
                'taille du JSON absente ou supérieure à la limite autorisée',
            );
        }
        if (! $this->artifactVerifier->validHistory($history)) {
            throw DemandForecastValidationException::at(
                'dataset.content_sha256',
                'le snapshot privé lié est absent ou altéré',
            );
        }

        $validated = $this->validator->validate($contents, $history);
        $disk = Storage::disk((string) config('intelligence.demand_forecasting.disk'));
        $candidateStoredPath = 'intelligence/demand-forecasts/'.Str::uuid().'.json';
        $storedPath = null;

        try {
            return DB::transaction(function () use (
                $history,
                $actor,
                $validated,
                $disk,
                $candidateStoredPath,
                &$storedPath,
            ): DemandForecastImportResult {
                DB::selectOne(
                    'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
                    [implode('|', [
                        'demand-forecast',
                        $history->tenant_id,
                        $history->agency_id,
                        $validated->idempotencyKey,
                    ])],
                );

                $existing = $this->idempotencyKeyInScope(
                    $validated->idempotencyKey,
                    $history->agency_id,
                    true,
                );
                if ($existing !== null) {
                    return $this->replay($existing, $validated, $history);
                }

                $storedPath = $candidateStoredPath;
                if (! $disk->put($storedPath, $validated->canonicalJson, ['visibility' => 'private'])) {
                    throw new RuntimeException('Impossible de conserver le lot de prévisions privé.');
                }

                $model = $validated->payload['model'];
                $evaluation = $validated->payload['evaluation'];
                $run = DemandForecastRun::create([
                    'agency_id' => $history->agency_id,
                    'demand_history_export_run_id' => $history->id,
                    'run_id' => $validated->batchId,
                    'idempotency_key' => $validated->idempotencyKey,
                    'schema_version' => $validated->payload['schema_version'],
                    'model_name' => $model['name'],
                    'model_version' => $model['version'],
                    'model_artifact_sha256' => $model['artifact_sha256'],
                    'framework' => $model['framework'],
                    'framework_version' => $model['framework_version'],
                    'compute' => $model['compute'],
                    'explanation_method' => $model['explanation_method'],
                    'mode' => $validated->payload['safety']['mode'],
                    'validation_scope' => $evaluation['validation_scope'],
                    'target_semantics' => DemandForecastContract::TARGET,
                    'generated_at' => $validated->generatedAt,
                    'as_of_date' => $history->date_to,
                    'input_row_count' => $history->row_count,
                    'input_content_sha256' => $history->content_sha256,
                    'result_count' => count($validated->forecasts),
                    'public_wape' => $evaluation['public_wape'],
                    'public_mase' => $evaluation['public_mase'],
                    'public_interval_coverage' => $evaluation['public_interval_coverage_p05_p95'],
                    'local_holdout_status' => $evaluation['local_holdout_status'],
                    'local_wape' => $evaluation['local_wape'],
                    'local_mase' => $evaluation['local_mase'],
                    'local_interval_coverage' => $evaluation['local_interval_coverage_p05_p95'],
                    'canonical_payload_sha256' => $validated->canonicalPayloadSha256,
                    'content_sha256' => hash('sha256', $validated->canonicalJson),
                    'byte_size' => strlen($validated->canonicalJson),
                    'stored_path' => $storedPath,
                    'original_name' => 'rentfleet_demand_forecast_'.$validated->batchId.'.json',
                    'validation_status' => 'validated',
                    'operational_effect' => DemandForecastContract::OPERATIONAL_EFFECT,
                    'imported_by' => $actor->id,
                    'imported_at' => now(),
                ]);

                foreach ($validated->forecasts as $position => $forecast) {
                    DemandForecast::create([
                        'agency_id' => $history->agency_id,
                        'demand_forecast_run_id' => $run->id,
                        'row_position' => $position,
                        'target_date' => $forecast['target_date'],
                        'horizon' => $forecast['horizon'],
                        'vehicle_category_scope' => $forecast['vehicle_category'],
                        'conditional_mean' => $forecast['conditional_mean'],
                        'p05' => $forecast['p05'],
                        'p50' => $forecast['p50'],
                        'p90' => $forecast['p90'],
                        'p95' => $forecast['p95'],
                        'raw_any_crossing' => $forecast['raw_any_crossing'],
                        'monotone_adjusted' => $forecast['monotone_adjusted'],
                        'explanations' => $forecast['explanations'],
                        'demand_semantics' => $forecast['demand_semantics'],
                        'operational_effect' => DemandForecastContract::OPERATIONAL_EFFECT,
                        'created_at' => now(),
                    ]);
                }

                $this->recordAudit('prediction.demand_forecast.imported', $run, 'CREATED');

                return new DemandForecastImportResult($run, true);
            }, 3);
        } catch (QueryException $exception) {
            if ($storedPath !== null) {
                $disk->delete($storedPath);
            }
            if ((string) $exception->getCode() !== '23505') {
                throw $exception;
            }

            $existing = $this->idempotencyKeyInScope(
                $validated->idempotencyKey,
                $history->agency_id,
            );
            if ($existing === null) {
                throw $exception;
            }

            return $this->replay($existing, $validated, $history);
        } catch (Throwable $exception) {
            if ($storedPath !== null) {
                $disk->delete($storedPath);
            }

            throw $exception;
        }
    }

    private function idempotencyKeyInScope(
        string $key,
        int $agencyId,
        bool $lock = false,
    ): ?DemandForecastRun {
        $query = DemandForecastRun::query()
            ->where('agency_id', $agencyId)
            ->where('idempotency_key', $key);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function replay(
        DemandForecastRun $existing,
        ValidatedDemandForecastBatch $validated,
        DemandHistoryExportRun $history,
    ): DemandForecastImportResult {
        if ($existing->demand_history_export_run_id !== $history->id
            || ! hash_equals($existing->canonical_payload_sha256, $validated->canonicalPayloadSha256)
            || ! $this->artifactVerifier->validForecast($existing)) {
            throw new DemandForecastIdempotencyConflictException;
        }

        $this->recordAudit('prediction.demand_forecast.replayed', $existing, 'REPLAY_SAFE');

        return new DemandForecastImportResult($existing, false);
    }

    private function assertActor(DemandHistoryExportRun $history, User $actor): void
    {
        if ($actor->tenant_id !== $history->tenant_id
            || $this->context->tenantId() !== $history->tenant_id
            || ! $actor->is_active
            || ! $actor->hasPermission('prediction.forecast.import')
            || ($actor->agency_id !== null && $actor->agency_id !== $history->agency_id)) {
            throw new AuthorizationException;
        }
    }

    private function recordAudit(string $action, DemandForecastRun $run, string $outcome): void
    {
        $this->audit->record($action, $run, [], [
            'forecast_run_id' => $run->run_id,
            'history_run_id' => $run->historyExport->run_id,
            'model_name' => $run->model_name,
            'model_version' => $run->model_version,
            'validation_scope' => $run->validation_scope,
            'result_count' => $run->result_count,
            'distance_unit' => DemandForecastContract::DISTANCE_UNIT,
            'outcome' => $outcome,
            'effect' => DemandForecastContract::OPERATIONAL_EFFECT,
        ]);
    }
}
