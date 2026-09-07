<?php

namespace App\Http\Controllers;

use App\Actions\Operations\RefreshPlatformOperationalIncidents;
use App\Models\PlatformOperationalIncident;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class PlatformOperationsController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'resolved'])],
            'severity' => ['nullable', Rule::in(['warning', 'critical'])],
        ]);
        $query = PlatformOperationalIncident::query()
            ->with(['events' => fn ($events) => $events->latest('observed_at')->limit(5)])
            ->when($filters['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status))
            ->when($filters['severity'] ?? null, fn ($builder, $severity) => $builder->where('severity', $severity))
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN severity = 'critical' THEN 0 ELSE 1 END")
            ->latest('last_detected_at');

        $lastCheckedAt = DB::table('operational_heartbeats')
            ->where('component', config('operations.monitoring.heartbeat_component'))
            ->value('last_succeeded_at');
        $heartbeatMaxAge = (int) config('operations.scheduler.heartbeat_max_age_minutes');

        return view('platform.operations.index', [
            'incidents' => $query->paginate(20)->withQueryString(),
            'counts' => [
                'open' => PlatformOperationalIncident::query()->where('status', 'open')->count(),
                'critical' => PlatformOperationalIncident::query()->where('status', 'open')->where('severity', 'critical')->count(),
                'resolved' => PlatformOperationalIncident::query()->where('status', 'resolved')->count(),
            ],
            'lastCheckedAt' => $lastCheckedAt,
            'monitoringFresh' => $lastCheckedAt !== null
                && CarbonImmutable::parse((string) $lastCheckedAt)->diffInSeconds(now(), true) <= ($heartbeatMaxAge * 60),
        ]);
    }

    public function refresh(RefreshPlatformOperationalIncidents $refresh): RedirectResponse
    {
        $summary = $refresh->handle();

        return back()->with('status', sprintf(
            'Supervision actualisée : %d contrôle(s), %d incident(s) ouvert(s), %d résolu(s).',
            $summary['checked'],
            $summary['open'],
            $summary['resolved'],
        ));
    }
}
