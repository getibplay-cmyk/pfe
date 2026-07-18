<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportFilterRequest;
use App\Models\Tenant;
use App\Support\Audit\AuditRecorder;
use App\Support\Export\SpreadsheetSafeCsv;
use App\Support\Reporting\BuildMinimalReport;
use App\Support\Reporting\ResolveReportCriteria;
use App\Support\Ui\UiLabel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    public function __invoke(
        ReportFilterRequest $request,
        ResolveReportCriteria $resolver,
        BuildMinimalReport $builder,
        AuditRecorder $audit,
    ): StreamedResponse {
        $criteria = $resolver->handle($request->validated());
        $report = $builder->handle($criteria);
        $tenant = Tenant::query()->findOrFail($criteria->tenantId);

        $audit->record('report.exported', $tenant, [], [
            'date_from' => $criteria->dateFrom(),
            'date_to' => $criteria->dateTo(),
            'agency_ids' => $criteria->agencyIds,
            'currency' => $criteria->currency,
            'format' => 'csv-summary',
        ]);

        $filename = sprintf('rapport_rentfleet_%s_%s.csv', $criteria->dateFrom(), $criteria->dateTo());

        return response()->streamDownload(function () use ($report): void {
            echo "\xEF\xBB\xBF";
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }

            $write = function (array $row) use ($output): void {
                fputcsv($output, array_map(SpreadsheetSafeCsv::cell(...), $row), ';');
            };
            $write(['Section', 'Indicateur', 'Valeur', 'Devise', 'Début', 'Fin exclusive', 'Fuseau horaire', 'Agences']);
            $meta = $report['meta'];
            $context = [$meta['period_start'], $meta['period_end_exclusive'], $meta['timezone'], implode(',', $meta['agency_ids'])];

            foreach ($report['operational']['reservations'] as $key => $value) {
                $write(['Exploitation', UiLabel::report('reservations.'.$key), $value, '', ...$context]);
            }
            foreach ($report['operational']['contracts'] as $key => $value) {
                $write(['Contrats', UiLabel::report('contracts.'.$key), $value, '', ...$context]);
            }
            foreach (array_diff_key($report['operational']['fleet'], ['snapshot_at' => true]) as $key => $value) {
                $write(['Flotte', UiLabel::report('fleet.'.$key), $value, '', ...$context]);
            }
            $write(['Flotte', UiLabel::report('utilization.rate'), $report['operational']['utilization']['rate'], '%', ...$context]);
            $write(['Flotte', UiLabel::report('utilization.duration'), $report['operational']['utilization']['occupied_duration'], '', ...$context]);
            foreach ($report['operational']['maintenance'] as $key => $value) {
                $write(['Maintenance', UiLabel::report('maintenance.'.$key), $value, '', ...$context]);
            }
            $write(['Assurance', UiLabel::report('insurance.open_claims'), $report['operational']['insurance']['open_claims'], '', ...$context]);
            $write(['Échéances', UiLabel::report('expirations.total'), $report['operational']['expirations']['total'], '', ...$context]);

            foreach ($report['financial']['currencies'] as $currency => $values) {
                foreach (['invoiced_amount', 'collected_net', 'outstanding_balance', 'held_deposits', 'retained_deposits', 'refunded_deposits', 'approved_expenses'] as $key) {
                    $write(['Finance', UiLabel::report('finance.'.$key), $values[$key], $currency, ...$context]);
                }
                foreach ($values['expenses'] as $status => $count) {
                    $write(['Finance', UiLabel::report('expenses.'.$status), $count, $currency, ...$context]);
                }
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
