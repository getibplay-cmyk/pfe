<?php

namespace App\Http\Controllers;

use App\Http\Requests\IntelligenceExportRequest;
use App\Models\Tenant;
use App\Support\Audit\AuditRecorder;
use App\Support\Export\SpreadsheetSafeCsv;
use App\Support\Intelligence\BuildRentalAnomalyInput;
use App\Support\Intelligence\IntelligencePseudonymizer;
use App\Support\Intelligence\PredictionInput;
use App\Support\Intelligence\RentalAnomalyDataset;
use App\Support\Reporting\ResolveReportCriteria;
use App\Support\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IntelligenceDatasetExportController extends Controller
{
    private const MAX_ROWS = 10000;

    public function __invoke(
        IntelligenceExportRequest $request,
        ResolveReportCriteria $resolver,
        RentalAnomalyDataset $dataset,
        BuildRentalAnomalyInput $builder,
        IntelligencePseudonymizer $pseudonymizer,
        TenantContext $context,
        AuditRecorder $audit,
    ): StreamedResponse {
        abort_unless($pseudonymizer->configured(), 503, 'Export Intelligence temporairement indisponible.');

        $criteria = $resolver->handle($request->validated());
        $query = $dataset->query($criteria);
        $eligibleRows = (clone $query)->limit(self::MAX_ROWS)->pluck('id')->count();
        $tenant = Tenant::query()->findOrFail($criteria->tenantId);

        $audit->record('prediction.dataset.exported', $tenant, [], [
            'schema_version' => PredictionInput::SCHEMA_VERSION,
            'dataset_version' => PredictionInput::DATASET_VERSION,
            'date_from' => $criteria->dateFrom(),
            'date_to' => $criteria->dateTo(),
            'agency_ids' => $criteria->agencyIds,
            'eligible_rows' => min($eligibleRows, self::MAX_ROWS),
            'max_rows' => self::MAX_ROWS,
            'format' => 'csv',
        ]);

        $filename = sprintf('rentfleet_intelligence_returns_%s_%s.csv', $criteria->dateFrom(), $criteria->dateTo());
        $contextAgencyId = $context->agencyId();

        return response()->streamDownload(function () use ($query, $builder, $context, $criteria, $contextAgencyId): void {
            echo "\xEF\xBB\xBF";
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }

            fputcsv($output, PredictionInput::headers(), ';', '"', '');
            $context->run($criteria->tenantId, function () use ($query, $builder, $output): void {
                $written = 0;
                foreach ($query->lazyById(500) as $contract) {
                    if ($written >= self::MAX_ROWS) {
                        break;
                    }

                    $input = $builder->handle($contract);
                    if ($input === null) {
                        continue;
                    }

                    fputcsv($output, array_map(SpreadsheetSafeCsv::cell(...), array_values($input->toExportRow())), ';', '"', '');
                    $written++;
                }
            }, $contextAgencyId);

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
