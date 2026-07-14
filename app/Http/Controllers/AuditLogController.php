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
        $logs = AuditLog::query()
            ->when($request->user()->isAgencyManager(), fn ($query) => $query->where('agency_id', $request->user()->agency_id))
            ->latest('created_at')
            ->paginate(30);

        return view('audit-logs.index', compact('logs'));
    }
}
