<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportFilterRequest;
use App\Models\Agency;
use App\Support\Reporting\BuildMinimalReport;
use App\Support\Tenancy\AgencyAccess;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(ReportFilterRequest $request, AgencyAccess $access, TenantContext $context, BuildMinimalReport $report): View
    {
        $data = $request->validated();
        $agencyId = $context->agencyId() !== null
            ? $access->required($data['agency_id'] ?? $context->agencyId())
            : $access->optional($data['agency_id'] ?? null);
        $from = CarbonImmutable::parse($data['date_from'])->startOfDay();
        $until = CarbonImmutable::parse($data['date_to'])->addDay()->startOfDay();

        return view('reports.index', [
            'report' => $report->handle($from, $until, $agencyId, $context->tenantId()),
            'agencies' => Agency::query()->when($context->agencyId(), fn ($query, $id) => $query->whereKey($id))->orderBy('name')->get(),
            'filters' => [...$data, 'agency_id' => $agencyId],
        ]);
    }
}
