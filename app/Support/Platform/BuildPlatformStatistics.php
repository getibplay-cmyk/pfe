<?php

namespace App\Support\Platform;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class BuildPlatformStatistics
{
    /** @var array<string, array{label: string, table: string}> */
    private const CAPABILITIES = [
        'demand_forecast' => [
            'label' => 'Prévision de demande',
            'table' => 'demand_forecast_execution_runs',
        ],
        'fleet_reallocation' => [
            'label' => 'Suggestion de réallocation',
            'table' => 'fleet_reallocation_planning_runs',
        ],
        'rental_usage_anomaly' => [
            'label' => 'Usages atypiques',
            'table' => 'rental_usage_anomaly_runs',
        ],
        'vehicle_color' => [
            'label' => 'Couleur suggérée',
            'table' => 'vehicle_color_prediction_runs',
        ],
        'vehicle_plate' => [
            'label' => 'Immatriculation détectée',
            'table' => 'vehicle_plate_prediction_runs',
        ],
        'vehicle_damage' => [
            'label' => 'Analyse des dommages',
            'table' => 'vehicle_damage_prediction_runs',
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public function handle(CarbonImmutable $startsAt, CarbonImmutable $endsAt): array
    {
        $this->guardPeriod($startsAt, $endsAt);

        $tenantStates = $this->groupedCounts('tenants', 'status', fn ($query) => $query->whereNull('deleted_at'));
        $subscriptionStates = $this->groupedCounts('saas_subscriptions', 'status');
        $payments = $this->paymentSummary($startsAt, $endsAt);
        [$runStates, $monthlyRuns] = $this->runStatistics($startsAt, $endsAt);
        $activations = $this->activationCounts();

        $totals = [
            'tenants' => array_sum($tenantStates),
            'active_tenants' => $tenantStates['active'] ?? 0,
            'inactive_tenants' => ($tenantStates['suspended'] ?? 0) + ($tenantStates['archived'] ?? 0),
            'agencies' => DB::table('agencies')->whereNull('deleted_at')->count(),
            'users' => DB::table('users')->whereNotNull('tenant_id')->count(),
            'vehicles' => DB::table('vehicles')->whereNull('deleted_at')->count(),
            'reservations' => DB::table('reservations')->whereNull('deleted_at')->count(),
            'contracts' => DB::table('rental_contracts')->whereNull('deleted_at')->count(),
            'recorded_saas_payments' => $payments['recorded_count'],
            'enabled_capabilities' => array_sum(array_column($activations, 'tenant_count')),
            'jobs' => DB::table('jobs')->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
        ];

        return [
            'period' => [
                'date_from' => $startsAt->setTimezone(config('app.timezone'))->toDateString(),
                'date_to' => $endsAt->setTimezone(config('app.timezone'))->subDay()->toDateString(),
            ],
            'totals' => $totals,
            'tenant_states' => $this->labelStates($tenantStates, [
                'active' => 'Actives',
                'suspended' => 'Suspendues',
                'archived' => 'Archivées',
            ]),
            'subscription_states' => $this->labelStates($subscriptionStates, [
                'trialing' => 'Période d’essai',
                'active' => 'Actifs',
                'past_due' => 'Échéance dépassée',
                'suspended' => 'Suspendus',
                'cancelled' => 'Annulés',
                'expired' => 'Expirés',
            ]),
            'payments' => $payments,
            'activations' => $activations,
            'run_states' => $runStates,
            'monthly_runs' => $monthlyRuns,
            'charts' => $this->charts($tenantStates, $subscriptionStates, $monthlyRuns),
        ];
    }

    private function guardPeriod(CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        if (! $startsAt->lessThan($endsAt) || $startsAt->diffInDays($endsAt) > 366) {
            throw new InvalidArgumentException('La période des statistiques doit être comprise entre 1 et 366 jours.');
        }
    }

    /**
     * @param  (callable(mixed): void)|null  $scope
     * @return array<string, int>
     */
    private function groupedCounts(string $table, string $column, ?callable $scope = null): array
    {
        $query = DB::table($table);
        if ($scope !== null) {
            $scope($query);
        }

        return $query
            ->select($column)
            ->selectRaw('COUNT(*)::int AS aggregate')
            ->groupBy($column)
            ->pluck('aggregate', $column)
            ->map(fn ($value): int => (int) $value)
            ->all();
    }

    /** @return array{recorded_count: int, reversal_count: int, currencies: list<array{currency: string, amount: string}>} */
    private function paymentSummary(CarbonImmutable $startsAt, CarbonImmutable $endsAt): array
    {
        $rows = DB::table('saas_payments')
            ->where('occurred_at', '>=', $this->timestampBinding($startsAt))
            ->where('occurred_at', '<', $this->timestampBinding($endsAt))
            ->select('currency')
            ->selectRaw("COUNT(*) FILTER (WHERE entry_type = 'payment')::int AS recorded_count")
            ->selectRaw("COUNT(*) FILTER (WHERE entry_type = 'reversal')::int AS reversal_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN entry_type = 'payment' THEN amount ELSE -amount END), 0)::text AS net_amount")
            ->groupBy('currency')
            ->orderBy('currency')
            ->get();

        return [
            'recorded_count' => $rows->sum(fn ($row): int => (int) $row->recorded_count),
            'reversal_count' => $rows->sum(fn ($row): int => (int) $row->reversal_count),
            'currencies' => $rows->map(fn ($row): array => [
                'currency' => (string) $row->currency,
                'amount' => (string) $row->net_amount,
            ])->values()->all(),
        ];
    }

    /**
     * @return array{0: list<array{capability: string, label: string, status: string, total: int}>, 1: list<array{month: string, label: string, total: int}>}
     */
    private function runStatistics(CarbonImmutable $startsAt, CarbonImmutable $endsAt): array
    {
        $states = [];
        $monthly = [];

        foreach (self::CAPABILITIES as $capability => $definition) {
            $rows = DB::table($definition['table'])
                ->where('requested_at', '>=', $this->timestampBinding($startsAt))
                ->where('requested_at', '<', $this->timestampBinding($endsAt))
                ->select('status')
                ->selectRaw("to_char(date_trunc('month', requested_at AT TIME ZONE ?), 'YYYY-MM') AS month", [config('app.timezone')])
                ->selectRaw('COUNT(*)::int AS aggregate')
                ->groupByRaw('1, 2')
                ->orderBy('month')
                ->get();

            foreach ($rows->groupBy('status') as $status => $statusRows) {
                $states[] = [
                    'capability' => $capability,
                    'label' => $definition['label'],
                    'status' => (string) $status,
                    'total' => $statusRows->sum(fn ($row): int => (int) $row->aggregate),
                ];
            }
            foreach ($rows as $row) {
                $month = (string) $row->month;
                $monthly[$month] = ($monthly[$month] ?? 0) + (int) $row->aggregate;
            }
        }

        $monthlyRows = [];
        $month = $startsAt->setTimezone(config('app.timezone'))->startOfMonth();
        $lastMonth = $endsAt->setTimezone(config('app.timezone'))->subSecond()->startOfMonth();
        while ($month->lessThanOrEqualTo($lastMonth)) {
            $key = $month->format('Y-m');
            $monthlyRows[] = [
                'month' => $key,
                'label' => $month->translatedFormat('M Y'),
                'total' => $monthly[$key] ?? 0,
            ];
            $month = $month->addMonth();
        }

        return [$states, $monthlyRows];
    }

    private function timestampBinding(CarbonImmutable $timestamp): string
    {
        return $timestamp->utc()->toIso8601String();
    }

    /** @return list<array{capability: string, label: string, tenant_count: int}> */
    private function activationCounts(): array
    {
        $counts = DB::table('tenant_intelligence_accesses')
            ->where('enabled', true)
            ->select('capability')
            ->selectRaw('COUNT(*)::int AS aggregate')
            ->groupBy('capability')
            ->pluck('aggregate', 'capability');

        return collect(self::CAPABILITIES)->map(
            fn (array $definition, string $capability): array => [
                'capability' => $capability,
                'label' => $definition['label'],
                'tenant_count' => (int) ($counts[$capability] ?? 0),
            ],
        )->values()->all();
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, string>  $labels
     * @return list<array{status: string, label: string, total: int}>
     */
    private function labelStates(array $counts, array $labels): array
    {
        return collect($labels)->map(fn (string $label, string $status): array => [
            'status' => $status,
            'label' => $label,
            'total' => $counts[$status] ?? 0,
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function charts(array $tenantStates, array $subscriptionStates, array $monthlyRuns): array
    {
        return [
            'states' => [
                'labels' => ['Entreprises actives', 'Entreprises suspendues', 'Essais', 'Abonnements actifs', 'Échéances dépassées'],
                'values' => [
                    $tenantStates['active'] ?? 0,
                    $tenantStates['suspended'] ?? 0,
                    $subscriptionStates['trialing'] ?? 0,
                    $subscriptionStates['active'] ?? 0,
                    $subscriptionStates['past_due'] ?? 0,
                ],
            ],
            'activity' => [
                'labels' => array_column($monthlyRuns, 'label'),
                'values' => array_column($monthlyRuns, 'total'),
            ],
        ];
    }
}
