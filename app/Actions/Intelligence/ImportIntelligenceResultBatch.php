<?php

namespace App\Actions\Intelligence;

use App\Exceptions\J14ResultBatchIdempotencyConflictException;
use App\Exceptions\J14ResultBatchValidationException;
use App\Models\IntelligenceDatasetExportRun;
use App\Models\IntelligenceResultBatch;
use App\Models\IntelligenceResultRow;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\J14\J14ResultBatchArtifactVerifier;
use App\Support\Intelligence\J14\J14ResultBatchImportResult;
use App\Support\Intelligence\J14\J14ResultBatchValidator;
use App\Support\Intelligence\J14\J14ValidatedResultBatch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ImportIntelligenceResultBatch
{
    public function __construct(
        private readonly J14ResultBatchValidator $validator,
        private readonly J14ResultBatchArtifactVerifier $artifactVerifier,
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(
        IntelligenceDatasetExportRun $run,
        UploadedFile $file,
        User $actor,
    ): J14ResultBatchImportResult {
        $this->assertActor($run, $actor);
        $maximumBytes = (int) config('intelligence.result_batches.max_upload_kilobytes') * 1024;
        $uploadedBytes = $file->getSize();
        if (! is_int($uploadedBytes) || $uploadedBytes <= 0 || $uploadedBytes > $maximumBytes) {
            throw J14ResultBatchValidationException::at(
                '$',
                'taille du fichier JSON absente ou supérieure à la limite autorisée',
            );
        }

        $realPath = $file->getRealPath();
        $contents = is_string($realPath) ? file_get_contents($realPath) : false;
        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('Le lot de résultats téléversé est vide ou illisible.');
        }

        $validated = $this->validator->validate($contents, $run);
        $disk = Storage::disk((string) config('intelligence.result_batches.disk'));
        $candidateStoredPath = 'intelligence/result-batches/'.Str::uuid().'.json';
        $storedPath = null;

        try {
            return DB::transaction(function () use (
                $run,
                $actor,
                $validated,
                $disk,
                $candidateStoredPath,
                &$storedPath,
            ): J14ResultBatchImportResult {
                DB::selectOne(
                    'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
                    [implode('|', [
                        'j14-b',
                        $run->tenant_id,
                        $run->agency_id ?? 'tenant',
                        $validated->idempotencyKey,
                    ])],
                );

                $existing = $this->idempotencyKeyInScope(
                    $validated->idempotencyKey,
                    $run->agency_id,
                    true,
                );
                if ($existing !== null) {
                    if ($storedPath !== null) {
                        $disk->delete($storedPath);
                        $storedPath = null;
                    }

                    return $this->replay($existing, $validated, $run);
                }

                $storedPath = $candidateStoredPath;
                if (! $disk->put($storedPath, $validated->canonicalJson, ['visibility' => 'private'])) {
                    throw new RuntimeException('Impossible de conserver le lot de résultats privé.');
                }

                $source = $validated->payload['source'];
                $batch = IntelligenceResultBatch::create([
                    'agency_id' => $run->agency_id,
                    'intelligence_dataset_export_run_id' => $run->id,
                    'batch_id' => $validated->batchId,
                    'idempotency_key' => $validated->idempotencyKey,
                    'schema_version' => $validated->payload['schema_version'],
                    'dataset_schema_version' => $run->schema_version,
                    'dataset_version' => $run->dataset_version,
                    'export_row_count' => $run->row_count,
                    'export_content_sha256' => $run->content_sha256,
                    'source_kind' => $source['kind'],
                    'computation_status' => $source['computation_status'],
                    'producer_name' => $source['producer_name'],
                    'producer_version' => $source['producer_version'],
                    'producer_environment' => $source['environment'],
                    'generated_at' => $validated->generatedAt,
                    'result_count' => count($validated->rows),
                    'canonical_payload_sha256' => $validated->canonicalPayloadSha256,
                    'content_sha256' => hash('sha256', $validated->canonicalJson),
                    'byte_size' => strlen($validated->canonicalJson),
                    'stored_path' => $storedPath,
                    'original_name' => 'rentfleet_j14_result_batch_'.$validated->batchId.'.json',
                    'validation_status' => 'validated',
                    'operational_effect' => 'NO_OPERATIONAL_ACTION',
                    'imported_by' => $actor->id,
                    'imported_at' => now(),
                ]);

                foreach ($validated->rows as $position => $row) {
                    IntelligenceResultRow::create([
                        'agency_id' => $batch->agency_id,
                        'intelligence_result_batch_id' => $batch->id,
                        'row_position' => $position,
                        'row_key' => $row['row_id'],
                        'advisory_kind' => $row['advisory_kind'],
                        'priority' => $row['priority'],
                        'summary_code' => $row['summary_code'],
                        'factors' => $row['factors'],
                        'operational_effect' => 'NO_OPERATIONAL_ACTION',
                        'created_at' => now(),
                    ]);
                }

                $this->recordAudit('prediction.result_batch.imported', $batch, 'CREATED');

                return new J14ResultBatchImportResult($batch, true);
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
                $run->agency_id,
            );
            if ($existing === null) {
                throw $exception;
            }

            return $this->replay($existing, $validated, $run);
        } catch (Throwable $exception) {
            if ($storedPath !== null) {
                $disk->delete($storedPath);
            }

            throw $exception;
        }
    }

    private function idempotencyKeyInScope(
        string $key,
        ?int $agencyId,
        bool $lock = false,
    ): ?IntelligenceResultBatch {
        $query = IntelligenceResultBatch::query()
            ->where('idempotency_key', $key)
            ->when(
                $agencyId === null,
                fn ($query) => $query->whereNull('agency_id'),
                fn ($query) => $query->where('agency_id', $agencyId),
            );

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function replay(
        IntelligenceResultBatch $existing,
        J14ValidatedResultBatch $validated,
        IntelligenceDatasetExportRun $run,
    ): J14ResultBatchImportResult {
        if ($existing->intelligence_dataset_export_run_id !== $run->id
            || ! hash_equals($existing->canonical_payload_sha256, $validated->canonicalPayloadSha256)
            || ! $this->artifactVerifier->valid($existing)) {
            throw new J14ResultBatchIdempotencyConflictException;
        }

        $this->recordAudit('prediction.result_batch.replayed', $existing, 'REPLAY_SAFE');

        return new J14ResultBatchImportResult($existing, false);
    }

    private function assertActor(IntelligenceDatasetExportRun $run, User $actor): void
    {
        if ($actor->tenant_id !== $run->tenant_id
            || $this->context->tenantId() !== $run->tenant_id
            || ! $actor->is_active
            || ! $actor->hasPermission('prediction.demo.review')
            || ($actor->agency_id !== null && $actor->agency_id !== $run->agency_id)) {
            throw new AuthorizationException;
        }
    }

    private function recordAudit(
        string $action,
        IntelligenceResultBatch $batch,
        string $outcome,
    ): void {
        $this->audit->record($action, $batch, [], [
            'batch_id' => $batch->batch_id,
            'run_id' => $batch->exportRun->run_id,
            'schema_version' => $batch->schema_version,
            'result_count' => $batch->result_count,
            'source_kind' => $batch->source_kind,
            'computation_status' => $batch->computation_status,
            'outcome' => $outcome,
            'effect' => 'NO_OPERATIONAL_ACTION',
        ]);
    }
}
