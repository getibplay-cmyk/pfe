<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TenantController extends Controller
{
    public function show(Request $request): View
    {
        $tenant = Tenant::findOrFail($request->user()->tenant_id);
        $this->authorize('view', $tenant);

        return view('tenant.show', compact('tenant'));
    }
}
