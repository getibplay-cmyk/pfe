<?php

namespace App\Http\Controllers;

use App\Actions\Platform\SetTenantIntelligenceAccess;
use App\Enums\IntelligenceCapability;
use App\Http\Requests\Platform\UpdateTenantIntelligenceAccessRequest;
use App\Models\Tenant;
use App\Support\Intelligence\IntelligenceCapabilityCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PlatformIntelligenceController extends Controller
{
    public function index(Request $request, IntelligenceCapabilityCatalog $catalog): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,suspended,archived'],
        ]);
        $tenants = Tenant::query()
            ->when($filters['q'] ?? null, fn ($query, string $search) => $query
                ->where(fn ($nested) => $nested
                    ->where('name', 'ilike', '%'.$search.'%')
                    ->orWhere('slug', 'ilike', '%'.$search.'%')))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        $tenantIds = $tenants->getCollection()->modelKeys();
        $accesses = DB::table('tenant_intelligence_accesses')
            ->whereIn('tenant_id', $tenantIds)
            ->get(['tenant_id', 'capability', 'enabled', 'changed_at'])
            ->groupBy('tenant_id');
        $enabledCounts = DB::table('tenant_intelligence_accesses')
            ->where('enabled', true)
            ->select('capability')
            ->selectRaw('COUNT(*)::int AS aggregate')
            ->groupBy('capability')
            ->pluck('aggregate', 'capability');
        $latestChanges = DB::table('tenant_intelligence_accesses')
            ->select('capability')
            ->selectRaw('MAX(changed_at) AS latest_change')
            ->groupBy('capability')
            ->pluck('latest_change', 'capability');

        $capabilities = collect($catalog->all())->map(function (array $definition, string $key) use ($catalog, $enabledCounts, $latestChanges): array {
            $capability = $definition['capability'];
            $globallyEnabled = $catalog->globallyEnabled($capability);
            $runtimeReady = $catalog->runtimeReady($capability);

            return [
                ...$definition,
                'globally_enabled' => $globallyEnabled,
                'runtime_ready' => $runtimeReady,
                'enabled_tenants' => (int) ($enabledCounts[$key] ?? 0),
                'latest_change' => isset($latestChanges[$key])
                    ? CarbonImmutable::parse((string) $latestChanges[$key])
                    : null,
                'message' => $globallyEnabled && $runtimeReady
                    ? 'Disponible sous réserve de l’autorisation de l’entreprise.'
                    : 'Configuration de la plateforme requise.',
            ];
        })->values();

        return view('platform.intelligence.index', compact('tenants', 'accesses', 'capabilities'));
    }

    public function update(
        UpdateTenantIntelligenceAccessRequest $request,
        Tenant $tenant,
        string $capability,
        SetTenantIntelligenceAccess $setAccess,
    ): RedirectResponse {
        $resolved = IntelligenceCapability::tryFrom($capability);
        abort_if($resolved === null, 404);

        $enabled = $request->boolean('enabled');
        $setAccess->handle($tenant, $resolved, $enabled, $request->user());

        return back()->with('status', $enabled
            ? 'Fonctionnalité autorisée pour cette entreprise.'
            : 'Fonctionnalité désactivée pour les nouveaux traitements de cette entreprise.');
    }
}
