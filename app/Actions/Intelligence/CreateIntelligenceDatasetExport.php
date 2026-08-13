<?php

namespace App\Actions\Intelligence;

use App\Models\Agency;
use App\Models\IntelligenceDatasetExportRun;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Export\SpreadsheetSafeCsv;
use App\Support\Intelligence\BuildRentalAnomalyInput;
use App\Support\Intelligence\IntelligencePseudonymizer;
use App\Support\Intelligence\PredictionInput;
use App\Support\Intelligence\RentalAnomalyDataset;
use App\Support\Reporting\ReportCriteria;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class CreateIntelligenceDatasetExport
{
    public function __construct(
        private readonly RentalAnomalyDataset $dataset,
        private readonly BuildRentalAnomalyInput $builder,
        private readonly IntelligencePseudonymizer $pseudonymizer,
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(
        ReportCriteria $criteria,
        User $actor,
        ?int $scopeAgencyId,
    ): IntelligenceDatasetExportRun {
        $this->assertAuthorized($criteria, $actor, $scopeAgencyId);

        $runId = (string) Str::uuid();
        $storedPath = 'intelligence/dataset-exports/'.$runId.'.csv';
        $filename = sprintf('rentfleet_intelligence_returns_%s_%s.csv', $criteria->dateFrom(), $criteria->dateTo());
        $stream = tmpfile();

        if ($stream === false) {
            throw new RuntimeException('Impossible de préparer le snapshot Intelligence privé.');
        }

        $disk = Storage::disk((string) config('intelligence.dataset_exports.disk'));

        try {
            $rowCount = $this->writeSnapshot($stream, $criteria);
            $metadata = $this->snapshotMetadata($stream);
            rewind($stream);

            if (! $disk->writeStream($storedPath, $stream)) {
                throw new RuntimeException('Impossible de conserver le snapshot Intelligence privé.');
            }

            return DB::transaction(function () use (
                $criteria,
                $actor,
                $scopeAgencyId,
                $runId,
                $storedPath,
                $filename,
                $rowCount,
                $metadata,
            ): IntelligenceDatasetExportRun {
                $scopeKind = $scopeAgencyId === null ? 'tenant' : 'agency';
                $scopeKey = $scopeAgencyId === null
                    ? $this->pseudonymizer->tenantKey($criteria->tenantId)
                    : $this->pseudonymizer->agencyKey($criteria->tenantId, $scopeAgencyId);
                $run = IntelligenceDatasetExportRun::create([
                    'agency_id' => $scopeAgencyId,
                    'run_id' => $runId,
                    'manifest_version' => (string) config('intelligence.dataset_exports.manifest_version'),
                    'schema_version' => PredictionInput::SCHEMA_VERSION,
                    'dataset_version' => PredictionInput::DATASET_VERSION,
                    'scope_kind' => $scopeKind,
                    'scope_key' => $scopeKey,
                    'date_from' => $criteria->dateFrom(),
                    'date_to' => $criteria->dateTo(),
                    'timezone' => $criteria->timezone,
                    'row_count' => $rowCount,
                    'max_rows' => (int) config('intelligence.dataset_exports.max_rows'),
                    'content_sha256' => $metadata['sha256'],
                    'byte_size' => $metadata['bytes'],
                    'format' => 'csv',
                    'stored_path' => $storedPath,
                    'original_name' => $filename,
                    'operational_effect' => 'NO_OPERATIONAL_ACTION',
                    'created_by' => $actor->id,
                    'created_at' => now(),
                ]);

                $this->audit->record('prediction.dataset.exported', $run, [], [
                    'run_id' => $run->run_id,
                    'schema_version' => $run->schema_version,
                    'dataset_version' => $run->dataset_version,
                    'date_from' => $run->date_from->toDateString(),
                    'date_to' => $run->date_to->toDateString(),
                    'scope_kind' => $run->scope_kind,
                    'row_count' => $run->row_count,
                    'max_rows' => $run->max_rows,
                    'format' => $run->format,
                    'operational_effect' => $run->operational_effect,
                ]);

                return $run;
            }, 3);
        } catch (Throwable $exception) {
            $disk->delete($storedPath);

            throw $exception;
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private function writeSnapshot($stream, ReportCriteria $criteria): int
    {
        if (fwrite($stream, "\xEF\xBB\xBF") !== 3
            || fputcsv($stream, PredictionInput::headers(), ';', '"', '', "\n") === false) {
            throw new RuntimeException('Impossible de générer le snapshot Intelligence.');
        }

        $maxRows = (int) config('intelligence.dataset_exports.max_rows');
        $query = $this->dataset->query($criteria);

        return $this->context->run($criteria->tenantId, function () use ($query, $stream, $maxRows): int {
            $written = 0;

            foreach ($query->lazyById(500) as $contract) {
                if ($written >= $maxRows) {
                    break;
                }

                $input = $this->builder->handle($contract);
                if ($input === null) {
                    continue;
                }

                $row = array_map(SpreadsheetSafeCsv::cell(...), array_values($input->toExportRow()));
                if (fputcsv($stream, $row, ';', '"', '', "\n") === false) {
                    throw new RuntimeException('Impossible de générer une ligne du snapshot Intelligence.');
                }

                $written++;
            }

            return $written;
        }, $this->context->agencyId());
    }

    /**
     * @param  resource  $stream
     * @return array{sha256: string, bytes: int}
     */
    private function snapshotMetadata($stream): array
    {
        fflush($stream);
        $stats = fstat($stream);
        if ($stats === false || ! isset($stats['size']) || $stats['size'] <= 0) {
            throw new RuntimeException('Le snapshot Intelligence généré est vide.');
        }

        rewind($stream);
        $hash = hash_init('sha256');
        hash_update_stream($hash, $stream);

        return ['sha256' => hash_final($hash), 'bytes' => (int) $stats['size']];
    }

    private function assertAuthorized(ReportCriteria $criteria, User $actor, ?int $scopeAgencyId): void
    {
        $criteriaAgencyIds = $criteria->agencyIds;
        sort($criteriaAgencyIds);
        $expectedAgencyIds = $scopeAgencyId === null
            ? Agency::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all()
            : [$scopeAgencyId];

        if ($criteria->tenantId !== $this->context->tenantId()
            || $actor->tenant_id !== $criteria->tenantId
            || ! $actor->hasPermission('prediction.export')
            || ! $this->pseudonymizer->configured()
            || ($actor->agency_id !== null && $actor->agency_id !== $scopeAgencyId)
            || $criteriaAgencyIds !== $expectedAgencyIds) {
            throw new AuthorizationException;
        }
    }
}
