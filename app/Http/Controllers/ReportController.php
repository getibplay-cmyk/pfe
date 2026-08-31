<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportFilterRequest;
use App\Models\Agency;
use App\Support\Reporting\BelkhirSpaceReportPresenter;
use App\Support\Reporting\BuildMinimalReport;
use App\Support\Reporting\ResolveReportCriteria;
use App\Support\Tenancy\TenantContext;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(ReportFilterRequest $request, ResolveReportCriteria $resolver, BuildMinimalReport $report, BelkhirSpaceReportPresenter $presenter, TenantContext $context): View
    {
        $data = $request->validated();
        $criteria = $resolver->handle($data);

        $agencies = Agency::query()
            ->when($context->agencyId(), fn ($query, $agencyId) => $query->whereKey($agencyId))
            ->orderBy('name')->get();

        $canonicalReport = $report->handle($criteria);

        return view('reports.index', [
            'report' => $canonicalReport,
            'reportStatistics' => $presenter->present($canonicalReport),
            'reservationRows' => $report->reservationRows($criteria),
            'agencies' => $agencies,
            'selectedAgencyNames' => $agencies->whereIn('id', $criteria->agencyIds)->pluck('name'),
            'filters' => [
                ...$data,
                'agency_id' => count($criteria->agencyIds) === 1 ? $criteria->agencyIds[0] : null,
                'currency' => $criteria->currency,
            ],
        ]);
    }
}
