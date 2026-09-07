<?php

namespace App\Http\Controllers;

use App\Enums\Platform\TenantOnboardingInvitationStatus;
use App\Enums\TenantStatus;
use App\Models\Platform\TenantOnboardingInvitation;
use App\Models\PlatformOperationalIncident;
use App\Models\Tenant;
use App\Support\Platform\BuildPlatformStatistics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PlatformDashboardController extends Controller
{
    public function __invoke(BuildPlatformStatistics $statisticsBuilder): View
    {
        $ownerRoleId = DB::table('roles')->where('slug', 'tenant-owner')->whereNull('tenant_id')->value('id');
        $alerts = Tenant::query()
            ->where('status', TenantStatus::Active->value)
            ->withCount([
                'users as active_owner_count' => fn ($query) => $query
                    ->where('role_id', $ownerRoleId ?? -1)
                    ->where('is_active', true),
                'agencies as active_agency_count' => fn ($query) => $query
                    ->where('is_active', true),
            ])
            ->orderBy('name')
            ->get()
            ->map(function (Tenant $tenant): array {
                return [
                    'tenant' => $tenant,
                    'missing_owner' => $tenant->active_owner_count === 0,
                    'missing_agency' => $tenant->active_agency_count === 0,
                ];
            })
            ->filter(fn (array $alert): bool => $alert['missing_owner'] || $alert['missing_agency']);

        $timezone = (string) config('app.timezone', 'Africa/Casablanca');
        $endsAt = CarbonImmutable::now($timezone)->addDay()->startOfDay();
        $statistics = $statisticsBuilder->handle($endsAt->subDays(30), $endsAt);
        $operationalIncidents = PlatformOperationalIncident::query()
            ->where('status', 'open')
            ->orderByRaw("CASE WHEN severity = 'critical' THEN 0 ELSE 1 END")
            ->latest('last_detected_at')
            ->limit(5)
            ->get();
        $monitoringHeartbeat = DB::table('operational_heartbeats')
            ->where('component', config('operations.monitoring.heartbeat_component'))
            ->value('last_succeeded_at');
        $monitoringFresh = $monitoringHeartbeat !== null
            && CarbonImmutable::parse((string) $monitoringHeartbeat)->diffInSeconds(now(), true)
                <= ((int) config('operations.scheduler.heartbeat_max_age_minutes') * 60);

        return view('platform.dashboard', [
            'metrics' => [
                'Entreprises clientes' => $statistics['totals']['tenants'],
                'Entreprises clientes actives' => $statistics['totals']['active_tenants'],
                'Entreprises clientes suspendues' => $statistics['tenant_states'][1]['total'],
                'Agences' => $statistics['totals']['agencies'],
                'Utilisateurs' => $statistics['totals']['users'],
                'Véhicules' => $statistics['totals']['vehicles'],
                'Réservations' => $statistics['totals']['reservations'],
                'Contrats' => $statistics['totals']['contracts'],
                'Paiements SaaS sur 30 jours' => $statistics['totals']['recorded_saas_payments'],
                'Assistances autorisées' => $statistics['totals']['enabled_capabilities'],
                'Travaux en attente' => $statistics['totals']['jobs'],
                'Traitements en échec' => $statistics['totals']['failed_jobs'],
                'Invitations en attente' => TenantOnboardingInvitation::query()
                    ->where('status', TenantOnboardingInvitationStatus::Pending->value)
                    ->where('expires_at', '>', now())
                    ->count(),
                'Incidents opérationnels ouverts' => PlatformOperationalIncident::query()->where('status', 'open')->count(),
            ],
            'latestTenants' => Tenant::query()->latest()->limit(8)->get(),
            'alerts' => $alerts,
            'statistics' => $statistics,
            'operationalIncidents' => $operationalIncidents,
            'monitoringFresh' => $monitoringFresh,
        ]);
    }
}
