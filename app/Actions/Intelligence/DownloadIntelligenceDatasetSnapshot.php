<?php

namespace App\Actions\Intelligence;

use App\Models\IntelligenceDatasetExportRun;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadIntelligenceDatasetSnapshot
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(IntelligenceDatasetExportRun $run): StreamedResponse
    {
        $disk = Storage::disk((string) config('intelligence.dataset_exports.disk'));
        $stream = $disk->readStream($run->stored_path);

        abort_unless(is_resource($stream), 410, 'Le snapshot Intelligence privé est indisponible.');

        $hash = hash_init('sha256');
        $bytes = hash_update_stream($hash, $stream);
        $digest = hash_final($hash);

        if ($bytes !== $run->byte_size || ! hash_equals($run->content_sha256, $digest) || rewind($stream) === false) {
            fclose($stream);
            abort(409, 'Le contrôle d’intégrité du snapshot Intelligence a échoué.');
        }

        $this->audit->record('prediction.dataset.snapshot_downloaded', $run, [], [
            'run_id' => $run->run_id,
            'row_count' => $run->row_count,
            'format' => $run->format,
            'integrity_verified' => true,
            'operational_effect' => $run->operational_effect,
        ]);

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $run->original_name, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'X-RentFleet-Export-Run' => $run->run_id,
            'X-RentFleet-Snapshot-SHA256' => $run->content_sha256,
        ]);
    }
}
