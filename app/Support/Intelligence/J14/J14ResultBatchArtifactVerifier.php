<?php

namespace App\Support\Intelligence\J14;

use App\Models\IntelligenceResultBatch;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class J14ResultBatchArtifactVerifier
{
    public function read(IntelligenceResultBatch $batch): string
    {
        $disk = Storage::disk((string) config('intelligence.result_batches.disk'));

        try {
            $content = $disk->get($batch->stored_path);
        } catch (Throwable) {
            throw new RuntimeException('Le lot de résultats privé est indisponible.');
        }

        if (strlen($content) !== $batch->byte_size
            || ! hash_equals($batch->content_sha256, hash('sha256', $content))) {
            throw new RuntimeException('Le contrôle d’intégrité du lot de résultats a échoué.');
        }

        return $content;
    }

    public function valid(IntelligenceResultBatch $batch): bool
    {
        try {
            $this->read($batch);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}
