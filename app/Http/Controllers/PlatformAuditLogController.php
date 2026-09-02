<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlatformAuditLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'tenant_id' => ['nullable', 'integer', Rule::exists('tenants', 'id')],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $logs = AuditLog::withoutGlobalScopes()
            ->with(['user:id,name', 'tenant:id,name,slug'])
            ->when($filters['q'] ?? null, fn ($query, string $search) => $query->where(
                fn ($nested) => $nested
                    ->where('action', 'ilike', '%'.$search.'%')
                    ->orWhere('correlation_id', 'ilike', '%'.$search.'%'),
            ))
            ->when($filters['tenant_id'] ?? null, fn ($query, int $tenantId) => $query->where('tenant_id', $tenantId))
            ->when($filters['date_from'] ?? null, fn ($query, string $date) => $query->where('created_at', '>=', $date.' 00:00:00'))
            ->when($filters['date_to'] ?? null, fn ($query, string $date) => $query->where('created_at', '<=', $date.' 23:59:59.999999'))
            ->latest('created_at')
            ->paginate(40)
            ->withQueryString();

        return view('platform.audit-logs.index', [
            'logs' => $logs,
            'tenants' => Tenant::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
