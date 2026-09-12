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
        abort_unless($request->user()->hasPermission('invoice.view') && $request->user()->hasPermission('expense.view'), 403);
        $criteria = $resolver->handle($request->validated());
        // Materialize bounded results while the request's tenant context is active.
        $report = $reports->vehicleProfitability($criteria, 5001, 1);
        if ($report['vehicles']->total() > 5000) {
            throw ValidationException::withMessages(['agency_id' => __('Limitez le périmètre à 5 000 véhicules pour cet export.')]);
        }
        $audit->record('report.vehicle_profitability_exported', Tenant::findOrFail($criteria->tenantId), [], [
            'date_from' => $criteria->dateFrom(), 'date_to' => $criteria->dateTo(), 'agency_ids' => $criteria->agencyIds, 'currency' => $criteria->currency, 'vehicle_count' => $report['vehicles']->total(),
        ]);

        return response()->streamDownload(function () use ($report, $criteria): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            $write = fn (array $row) => fputcsv($output, array_map(SpreadsheetSafeCsv::cell(...), $row), ';', '"', '');
            $write(['Type', __('Véhicule'), 'Marque', __('Modèle'), 'Devise', __('Facturé'), __('Encaissé net'), __('Dépenses approuvées'), __('Marge connue partielle'), __('Immobilisation (secondes)'), 'Du', 'Au', __('Amortissement estimé'), __('Assurance non saisie'), __('Autres coûts non saisis'), __('Achat associé exclu des coûts estimés'), __('Marge économique estimée'), __('Jours avec hypothèses')]);
            foreach ($report['vehicles'] as $vehicle) {
                $actual = $report['amounts'][$vehicle->id] ?? [];
                $projected = $report['economics'][$vehicle->id] ?? [];
                $currencies = array_unique([...array_keys($actual), ...array_keys($projected)]);
                sort($currencies);
                if ($currencies === []) {
                    $write([__('Sans écriture'), $vehicle->registration_number, $vehicle->brand, $vehicle->model, $criteria->currency, '', '', '', '', $report['downtime'][$vehicle->id] ?? 0, $criteria->dateFrom(), $criteria->dateTo(), '', '', '', '', '', '']);
                }
                foreach ($currencies as $currency) {
                    $amounts = $actual[$currency] ?? [];
                    $costs = $projected[$currency] ?? [];
                    $write([__('Véhicule'), $vehicle->registration_number, $vehicle->brand, $vehicle->model, $currency, $amounts['invoiced'] ?? '0.00', $amounts['collected'] ?? '0.00', $amounts['expenses'] ?? '0.00', $amounts['margin'] ?? '0.00', $report['downtime'][$vehicle->id] ?? 0, $criteria->dateFrom(), $criteria->dateTo(), $costs['depreciation'] ?? '', $costs['insurance_unrecorded'] ?? '', $costs['other_unrecorded'] ?? '', $costs['acquisition_recorded'] ?? '', $costs['estimated_margin'] ?? '', $costs['coverage_days'] ?? '']);
                }
            }
            foreach ($report['unallocated'] as $expense) {
                $write([__('Frais non affectés, exclus des marges'), '', '', '', $expense->currency, '', '', $expense->amount, '', '', $criteria->dateFrom(), $criteria->dateTo(), '', '', '', '', '', '']);
            }
            fclose($output);
        }, 'rentabilite-vehicules-'.$criteria->dateFrom().'-'.$criteria->dateTo().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
