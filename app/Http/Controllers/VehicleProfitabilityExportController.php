<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportFilterRequest;
use App\Models\Tenant;
use App\Support\Audit\AuditRecorder;
use App\Support\Export\SpreadsheetSafeCsv;
use App\Support\Reporting\BuildMinimalReport;
use App\Support\Reporting\ResolveReportCriteria;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VehicleProfitabilityExportController extends Controller
{
    public function __invoke(ReportFilterRequest $request, ResolveReportCriteria $resolver, BuildMinimalReport $reports, AuditRecorder $audit): StreamedResponse
    {
        abort_unless($request->user()->hasPermission('report.export') && $request->user()->hasPermission('invoice.view') && $request->user()->hasPermission('expense.view'), 403);
        $criteria = $resolver->handle($request->validated());
        // Materialize bounded results while the request's tenant context is active.
        $report = $reports->vehicleProfitability($criteria, 5001, 1);
        if ($report['vehicles']->total() > 5000) {
            throw ValidationException::withMessages(['agency_id' => 'Limitez le périmètre à 5 000 véhicules pour cet export.']);
        }
        $audit->record('report.vehicle_profitability_exported', Tenant::findOrFail($criteria->tenantId), [], [
            'date_from' => $criteria->dateFrom(), 'date_to' => $criteria->dateTo(), 'agency_ids' => $criteria->agencyIds, 'currency' => $criteria->currency, 'vehicle_count' => $report['vehicles']->total(),
        ]);

        return response()->streamDownload(function () use ($report, $criteria): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            $write = fn (array $row) => fputcsv($output, array_map(SpreadsheetSafeCsv::cell(...), $row), ';', '"', '');
            $write(['Type', 'Véhicule', 'Marque', 'Modèle', 'Devise', 'Facturé', 'Encaissé net', 'Dépenses approuvées', 'Marge connue partielle', 'Immobilisation (secondes)', 'Du', 'Au']);
            foreach ($report['vehicles'] as $vehicle) {
                $currencies = $report['amounts'][$vehicle->id] ?? [];
                if ($currencies === []) {
                    $write(['Sans écriture', $vehicle->registration_number, $vehicle->brand, $vehicle->model, $criteria->currency, '', '', '', '', $report['downtime'][$vehicle->id] ?? 0, $criteria->dateFrom(), $criteria->dateTo()]);
                }
                foreach ($currencies as $currency => $amounts) {
                    $write(['Véhicule', $vehicle->registration_number, $vehicle->brand, $vehicle->model, $currency, $amounts['invoiced'], $amounts['collected'], $amounts['expenses'], $amounts['margin'], $report['downtime'][$vehicle->id] ?? 0, $criteria->dateFrom(), $criteria->dateTo()]);
                }
            }
            foreach ($report['unallocated'] as $expense) {
                $write(['Frais non affectés, exclus des marges', '', '', '', $expense->currency, '', '', $expense->amount, '', '', $criteria->dateFrom(), $criteria->dateTo()]);
            }
            fclose($output);
        }, 'rentabilite-vehicules-'.$criteria->dateFrom().'-'.$criteria->dateTo().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
