<?php

namespace App\Http\Controllers;

use App\Actions\Intelligence\CreateIntelligenceDatasetExport;
use App\Actions\Intelligence\DownloadIntelligenceDatasetSnapshot;
use App\Http\Requests\IntelligenceExportRequest;
use App\Support\Intelligence\IntelligencePseudonymizer;
use App\Support\Reporting\ResolveReportCriteria;
use App\Support\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IntelligenceDatasetExportController extends Controller
{
    public function __invoke(
        IntelligenceExportRequest $request,
        ResolveReportCriteria $resolver,
        IntelligencePseudonymizer $pseudonymizer,
        TenantContext $context,
        CreateIntelligenceDatasetExport $create,
        DownloadIntelligenceDatasetSnapshot $download,
    ): StreamedResponse {
        abort_unless($pseudonymizer->configured(), 503, 'Export Intelligence temporairement indisponible.');

        $filters = $request->validated();
        $criteria = $resolver->handle($filters);
        $requestedAgencyId = $filters['agency_id'] ?? null;
        $scopeAgencyId = $context->agencyId()
            ?? ($requestedAgencyId !== null && $requestedAgencyId !== '' ? $criteria->agencyIds[0] : null);
        $run = $create->handle($criteria, $request->user(), $scopeAgencyId);

        return $download->handle($run);
    }
}
