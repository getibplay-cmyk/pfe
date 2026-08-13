<?php

namespace App\Http\Controllers;

use App\Actions\Intelligence\DownloadIntelligenceDatasetSnapshot;
use App\Models\IntelligenceDatasetExportRun;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IntelligenceDatasetSnapshotDownloadController extends Controller
{
    public function __invoke(
        IntelligenceDatasetExportRun $exportRun,
        DownloadIntelligenceDatasetSnapshot $download,
    ): StreamedResponse {
        $this->authorize('view', $exportRun);

        return $download->handle($exportRun);
    }
}
