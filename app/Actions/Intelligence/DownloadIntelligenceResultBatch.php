<?php

namespace App\Actions\Intelligence;

use App\Models\IntelligenceResultBatch;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\J14\J14ResultBatchArtifactVerifier;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadIntelligenceResultBatch
{
    public function __construct(
        private readonly J14ResultBatchArtifactVerifier $verifier,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(IntelligenceResultBatch $batch): StreamedResponse
    {
        try {
            $content = $this->verifier->read($batch);
        } catch (RuntimeException $exception) {
            abort($exception->getMessage() === 'Le lot de résultats privé est indisponible.' ? 410 : 409, $exception->getMessage());
        }

        $this->audit->record('prediction.result_batch.downloaded', $batch, [], [
            'batch_id' => $batch->batch_id,
            'result_count' => $batch->result_count,
            'integrity_verified' => true,
            'effect' => 'NO_OPERATIONAL_ACTION',
        ]);

        return response()->streamDownload(
            static function () use ($content): void {
                echo $content;
            },
            $batch->original_name,
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
                'X-RentFleet-Result-Batch' => $batch->batch_id,
                'X-RentFleet-Result-SHA256' => $batch->content_sha256,
            ],
        );
    }
}
