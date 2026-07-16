<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', AuditLog::class);
        $logs = AuditLog::query()->with('user:id,name')
            ->when($request->user()->agency_id, fn ($query, $agencyId) => $query->where('agency_id', $agencyId))
            ->when($request->string('q')->isNotEmpty(), fn ($query) => $query->where('action', 'ilike', '%'.$request->string('q').'%'))
            ->latest('created_at')
            ->paginate(30)->withQueryString();

        return view('audit-logs.index', compact('logs'));
    }
}
