<?php

namespace App\Support\Security;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class IntelligenceCapacity
{
    private const TABLES = ['vehicle_color_prediction_runs', 'vehicle_damage_prediction_runs', 'vehicle_plate_prediction_runs', 'demand_forecast_execution_runs', 'rental_usage_anomaly_runs', 'fleet_reallocation_runs'];

    /** Reserve admission in the same transaction that creates the run. No cross-tenant data is returned. */
    public function assertAvailable(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Intelligence admission requires a transaction.');
        }
        $tenantId = app(TenantContext::class)->tenantId();
        $lock = DB::selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(CAST(? AS text), 0)) AS acquired', ['rentfleet:intelligence:admission:v1']);
        abort_unless($lock?->acquired, 503, __('Les traitements sont temporairement occupés. Réessayez dans quelques instants.'));
        $globalLimit = max(1, min(10000, (int) config('security.intelligence.global_pending', 100)));
        $tenantLimit = max(1, min(100, (int) config('security.intelligence.tenant_pending', 10)));
        $global = 0;
        $tenant = 0;
        foreach (self::TABLES as $table) {
            $query = DB::table($table)->whereIn('status', ['queued', 'running']);
            $global += (clone $query)->count();
            $tenant += $query->where('tenant_id', $tenantId)->count();
            abort_if($global >= $globalLimit || $tenant >= $tenantLimit, 429, __('La capacité de traitement est atteinte. Attendez la fin des tâches en cours.'));
        }
    }
}
