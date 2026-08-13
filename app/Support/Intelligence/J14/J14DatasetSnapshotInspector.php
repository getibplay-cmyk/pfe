<?php

namespace App\Support\Intelligence\J14;

use App\Exceptions\J14ResultBatchValidationException;
use App\Models\IntelligenceDatasetExportRun;
use App\Support\Intelligence\PredictionInput;
use Illuminate\Support\Facades\Storage;

final class J14DatasetSnapshotInspector
{
    public function inspect(IntelligenceDatasetExportRun $run): J14DatasetSnapshotInspection
    {
        $stream = Storage::disk((string) config('intelligence.dataset_exports.disk'))
            ->readStream($run->stored_path);

        if (! is_resource($stream)) {
            throw J14ResultBatchValidationException::at(
                'export.run_id',
                'le snapshot privé référencé est indisponible',
            );
        }

        try {
            $hash = hash_init('sha256');
            $bytes = hash_update_stream($hash, $stream);
            $digest = hash_final($hash);

            if ($bytes !== $run->byte_size || ! hash_equals($run->content_sha256, $digest)) {
                throw J14ResultBatchValidationException::at(
                    'export.content_sha256',
                    'l’intégrité du snapshot privé référencé a échoué',
                );
            }

            if (rewind($stream) === false) {
                throw J14ResultBatchValidationException::at('export.run_id', 'snapshot privé illisible');
            }

            $header = fgetcsv($stream, null, ';', '"', '');
            if (! is_array($header)) {
                throw J14ResultBatchValidationException::at('export.run_id', 'en-tête CSV absent');
            }

            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? '';
            if ($header !== PredictionInput::headers()) {
                throw J14ResultBatchValidationException::at('export.run_id', 'schéma CSV source inattendu');
            }

            $rowKeys = [];
            $seen = [];

            while (($row = fgetcsv($stream, null, ';', '"', '')) !== false) {
                if (count($row) !== count($header)) {
                    throw J14ResultBatchValidationException::at('export.run_id', 'ligne CSV source invalide');
                }

                $record = array_combine($header, $row);
                if ($record === false
                    || $record['schema_version'] !== PredictionInput::SCHEMA_VERSION
                    || $record['dataset_version'] !== PredictionInput::DATASET_VERSION
                    || preg_match('/^r_[0-9a-f]{64}$/D', $record['row_id']) !== 1
                    || isset($seen[$record['row_id']])) {
                    throw J14ResultBatchValidationException::at('export.run_id', 'lignée CSV source invalide');
                }

                $rowKeys[] = $record['row_id'];
                $seen[$record['row_id']] = true;
            }

            if (count($rowKeys) !== $run->row_count) {
                throw J14ResultBatchValidationException::at(
                    'export.row_count',
                    'le nombre de lignes du snapshot privé ne correspond plus au manifeste',
                );
            }

            return new J14DatasetSnapshotInspection($rowKeys);
        } finally {
            fclose($stream);
        }
    }
}
