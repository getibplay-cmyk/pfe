<?php

namespace App\Support\Intelligence\RentalUsageAnomaly;

use App\Exceptions\RentalUsageAnomalyExecutionException;
use App\Models\IntelligenceDatasetExportRun;
use App\Support\Intelligence\PredictionInput;
use Illuminate\Support\Facades\Storage;

final class RentalUsageAnomalySnapshotInspector
{
    public function inspect(IntelligenceDatasetExportRun $run): RentalUsageAnomalySnapshotInspection
    {
        $stream = Storage::disk((string) config('intelligence.dataset_exports.disk'))
            ->readStream($run->stored_path);
        if (! is_resource($stream)) {
            throw new RentalUsageAnomalyExecutionException('SOURCE_SNAPSHOT_INVALID');
        }

        try {
            $hash = hash_init('sha256');
            $bytes = hash_update_stream($hash, $stream);
            $digest = hash_final($hash);
            if ($bytes !== $run->byte_size || ! hash_equals($run->content_sha256, $digest)) {
                throw new RentalUsageAnomalyExecutionException('SOURCE_SNAPSHOT_INVALID');
            }
            if (rewind($stream) === false) {
                throw new RentalUsageAnomalyExecutionException('SOURCE_SNAPSHOT_INVALID');
            }

            $header = fgetcsv($stream, null, ';', '"', '');
            if (! is_array($header)) {
                throw new RentalUsageAnomalyExecutionException('SOURCE_SNAPSHOT_INVALID');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? '';
            if ($header !== PredictionInput::headers()) {
                throw new RentalUsageAnomalyExecutionException('SOURCE_SNAPSHOT_INVALID');
            }

            $rows = [];
            $tenantKey = null;
            while (($row = fgetcsv($stream, null, ';', '"', '')) !== false) {
                if (count($row) !== count($header)) {
                    throw new RentalUsageAnomalyExecutionException('SOURCE_SNAPSHOT_INVALID');
                }
                $record = array_combine($header, $row);
                if ($record === false
                    || $record['schema_version'] !== PredictionInput::SCHEMA_VERSION
                    || $record['dataset_version'] !== PredictionInput::DATASET_VERSION
                    || preg_match('/^r_[0-9a-f]{64}$/D', $record['row_id']) !== 1
                    || preg_match('/^t_[0-9a-f]{64}$/D', $record['tenant_key']) !== 1
                    || preg_match('/^a_[0-9a-f]{64}$/D', $record['agency_key']) !== 1
                    || preg_match('/^c_[0-9a-f]{64}$/D', $record['contract_key']) !== 1
                    || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $record['event_at']) !== 1
                    || isset($rows[$record['row_id']])) {
                    throw new RentalUsageAnomalyExecutionException('SOURCE_SNAPSHOT_INVALID');
                }
                $tenantKey ??= $record['tenant_key'];
                if ($record['tenant_key'] !== $tenantKey) {
                    throw new RentalUsageAnomalyExecutionException('SOURCE_SNAPSHOT_INVALID');
                }
                foreach (RentalUsageAnomalyContract::FEATURES as $feature) {
                    if (preg_match('/^(?:0|[1-9]\d{0,8})\.\d{6}$/D', $record[$feature]) !== 1) {
                        throw new RentalUsageAnomalyExecutionException('SOURCE_SNAPSHOT_INVALID');
                    }
                }
                $rows[$record['row_id']] = $record;
            }
            if (count($rows) !== $run->row_count) {
                throw new RentalUsageAnomalyExecutionException('SOURCE_SNAPSHOT_INVALID');
            }

            return new RentalUsageAnomalySnapshotInspection($rows);
        } finally {
            fclose($stream);
        }
    }
}
