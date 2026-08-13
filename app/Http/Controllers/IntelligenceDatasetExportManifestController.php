<?php

namespace App\Http\Controllers;

use App\Models\IntelligenceDatasetExportRun;
use App\Support\Audit\AuditRecorder;
use JsonException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IntelligenceDatasetExportManifestController extends Controller
{
    /** @throws JsonException */
    public function __invoke(
        IntelligenceDatasetExportRun $exportRun,
        AuditRecorder $audit,
    ): StreamedResponse {
        $this->authorize('view', $exportRun);
        $manifest = json_encode(
            $exportRun->manifest(),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        )."\n";

        $audit->record('prediction.dataset.manifest_downloaded', $exportRun, [], [
            'run_id' => $exportRun->run_id,
            'manifest_version' => $exportRun->manifest_version,
            'row_count' => $exportRun->row_count,
            'format' => 'json',
            'operational_effect' => $exportRun->operational_effect,
        ]);

        return response()->streamDownload(
            static function () use ($manifest): void {
                echo $manifest;
            },
            'rentfleet_intelligence_manifest_'.$exportRun->run_id.'.json',
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
