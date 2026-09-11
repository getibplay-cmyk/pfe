<?php

namespace App\Http\Controllers;

use App\Support\Reporting\DashboardActions;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardActionController extends Controller
{
    public function __invoke(Request $request, string $group, DashboardActions $actions): View
    {
        $request->validate(['tenant_id' => ['prohibited'], 'agency_id' => ['prohibited']]);

        return view('dashboard.actions', ['group' => $actions->for($request->user(), $group)[0]]);
    }
}
