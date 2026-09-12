<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Support\Intelligence\Training\ModelQualityReport;
use App\Support\Tenancy\AgencyAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ModelQualityController extends Controller
{
    public function __invoke(Request $request, ModelQualityReport $report, AgencyAccess $access)
    {
        abort_unless($request->user()->hasPermission('prediction.view'), 403);
        $data = $request->validate(['tenant_id' => ['prohibited'], 'agency_id' => ['nullable', 'integer', 'min:1'], 'days' => ['nullable', Rule::in([7, 30, 90])]]);
        $agency = isset($data['agency_id']) ? $access->required($data['agency_id']) : $request->user()->agency_id;
        $days = (int) ($data['days'] ?? 30);

        return view('intelligence.quality', [...$report->build($request->user(), $days, $agency), 'days' => $days, 'agency' => $agency, 'agencies' => Agency::when($request->user()->agency_id, fn ($q, $id) => $q->whereKey($id))->orderBy('name')->get(['id', 'name'])]);
    }
}
