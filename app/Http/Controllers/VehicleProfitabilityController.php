<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportFilterRequest;
use App\Models\Agency;
use App\Support\Reporting\BuildMinimalReport;
use App\Support\Reporting\ResolveReportCriteria;

class VehicleProfitabilityController extends Controller
{
    public function __invoke(ReportFilterRequest $request, ResolveReportCriteria $resolver, BuildMinimalReport $reports)
    {
        abort_unless($request->user()->hasPermission('invoice.view') && $request->user()->hasPermission('expense.view'), 403);
        $criteria = $resolver->handle($request->validated());

        return view('reports.vehicle-profitability', [
            ...$reports->vehicleProfitability($criteria), 'criteria' => $criteria,
            'agencies' => Agency::query()->when($request->user()->agency_id, fn ($query, $id) => $query->whereKey($id))->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
