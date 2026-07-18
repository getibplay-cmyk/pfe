<?php

namespace App\Support\Reporting;

use App\Models\Reservation;
use App\Models\Tenant;
use App\Support\Pricing\DecimalMoney;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Source canonique des KPI tenant/agence du dashboard, du rapport et de l'export.
 */
class BuildMinimalReport
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(ReportCriteria $criteria): array
    {
        $this->assertCriteria($criteria);

        $reservations = $this->reservationMetrics($criteria);
        $contracts = $this->contractMetrics($criteria);
        $fleet = $this->fleetMetrics($criteria);
        $utilization = $this->utilizationMetrics($criteria);
        $maintenance = $this->maintenanceMetrics($criteria);
        $insurance = $this->insuranceMetrics($criteria);
        $expirations = $this->expirationMetrics($criteria);
        $financial = $this->financialMetrics($criteria);

        return [
            'meta' => [
                'period_start' => $criteria->startsAt->toIso8601String(),
                'period_end_exclusive' => $criteria->endsAt->toIso8601String(),
                'date_from' => $criteria->dateFrom(),
                'date_to' => $criteria->dateTo(),
                'timezone' => $criteria->timezone,
                'agency_ids' => $criteria->agencyIds,
                'currency' => $criteria->currency,
                'available_currencies' => array_keys($financial['all_currencies']),
            ],
            'operational' => compact('reservations', 'contracts', 'fleet', 'utilization', 'maintenance', 'insurance', 'expirations'),
            'financial' => ['currencies' => $financial['visible_currencies']],
        ];
    }

    public function reservationRows(ReportCriteria $criteria, int $perPage = 15): LengthAwarePaginator
    {
        $this->assertCriteria($criteria);

        return Reservation::query()
            ->select(['id', 'agency_id', 'vehicle_category_id', 'reservation_number', 'starts_at', 'ends_at', 'status', 'total_amount', 'currency'])
            ->with(['agency:id,name', 'vehicleCategory:id,name'])
            ->where('tenant_id', $criteria->tenantId)
            ->whereIn('agency_id', $criteria->agencyIds)
            ->whereNull('deleted_at')
            ->where('starts_at', '<', $criteria->endsAt)
            ->where('ends_at', '>', $criteria->startsAt)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'reservations_page')
            ->withQueryString();
    }

    private function reservationMetrics(ReportCriteria $criteria): array
    {
        $created = $this->scoped('reservations', 'r', $criteria)
            ->whereNull('r.deleted_at')
            ->where('r.created_at', '>=', $criteria->startsAt)
            ->where('r.created_at', '<', $criteria->endsAt)
            ->count();

        $events = DB::table('reservation_status_histories as h')
            ->join('reservations as r', fn ($join) => $join->on('r.id', '=', 'h.reservation_id')->on('r.tenant_id', '=', 'h.tenant_id'))
            ->where('h.tenant_id', $criteria->tenantId)
            ->whereIn('r.agency_id', $criteria->agencyIds)
            ->whereNull('r.deleted_at')
            ->where('h.created_at', '>=', $criteria->startsAt)
            ->where('h.created_at', '<', $criteria->endsAt)
            ->whereIn('h.to_status', ['confirmed', 'cancelled', 'expired'])
            ->selectRaw('h.to_status, COUNT(*) AS aggregate')
            ->groupBy('h.to_status')
            ->pluck('aggregate', 'to_status');

        return [
            'created' => $created,
            'confirmed' => (int) ($events['confirmed'] ?? 0),
            'cancelled' => (int) ($events['cancelled'] ?? 0),
            'expired' => (int) ($events['expired'] ?? 0),
        ];
    }

    private function contractMetrics(ReportCriteria $criteria): array
    {
        $active = $this->scoped('rental_contracts', 'c', $criteria)
            ->whereNull('c.deleted_at')
            ->whereNotNull('c.activated_at')
            ->where('c.activated_at', '<', $criteria->endsAt)
            ->whereRaw("COALESCE(c.actual_return_at, c.returned_at, c.closed_at, c.cancelled_at, 'infinity'::timestamptz) > ?", [$criteria->startsAt])
            ->count();

        $expected = $this->scoped('rental_contracts', 'c', $criteria)
            ->whereNull('c.deleted_at')
            ->where('c.status', '<>', 'cancelled')
            ->where('c.expected_return_at', '>=', $criteria->startsAt)
            ->where('c.expected_return_at', '<', $criteria->endsAt)
            ->count();

        $asOf = $criteria->endsAt->lessThan(CarbonImmutable::now($criteria->timezone))
            ? $criteria->endsAt
            : CarbonImmutable::now($criteria->timezone);
        $overdue = $this->scoped('rental_contracts', 'c', $criteria)
            ->whereNull('c.deleted_at')
            ->where('c.status', '<>', 'cancelled')
            ->where('c.expected_return_at', '>=', $criteria->startsAt)
            ->where('c.expected_return_at', '<', $criteria->endsAt)
            ->where('c.expected_return_at', '<', $asOf)
            ->whereRaw('COALESCE(c.actual_return_at, c.returned_at, ?::timestamptz) > c.expected_return_at', [$asOf])
            ->count();

        $closed = $this->scoped('rental_contracts', 'c', $criteria)
            ->whereNull('c.deleted_at')
            ->whereNotNull('c.closed_at')
            ->where('c.closed_at', '>=', $criteria->startsAt)
            ->where('c.closed_at', '<', $criteria->endsAt)
            ->count();

        return ['active' => $active, 'expected_returns' => $expected, 'overdue_returns' => $overdue, 'closed' => $closed];
    }

    private function fleetMetrics(ReportCriteria $criteria): array
    {
        $now = CarbonImmutable::now($criteria->timezone);
        $snapshot = $criteria->endsAt->lessThanOrEqualTo($now) ? $criteria->endsAt->subMicrosecond() : $now;
        $agencies = $this->placeholders($criteria->agencyIds);
        $sql = <<<SQL
            SELECT
                COUNT(*) FILTER (WHERE status_at = 'active' AND block_type IS NULL) AS available,
                COUNT(*) FILTER (WHERE block_type = 'contract') AS rented,
                COUNT(*) FILTER (WHERE status_at = 'active' AND block_type IN ('reservation', 'manual')) AS blocked,
                COUNT(*) FILTER (WHERE status_at = 'maintenance' OR block_type = 'maintenance') AS maintenance
            FROM (
                SELECT v.id,
                    COALESCE((
                        SELECT h.to_status FROM vehicle_status_histories h
                        WHERE h.tenant_id = v.tenant_id AND h.vehicle_id = v.id AND h.created_at <= ?
                        ORDER BY h.created_at DESC, h.id DESC LIMIT 1
                    ), v.operational_status) AS status_at,
                    (
                        SELECT b.block_type FROM vehicle_blocks b
                        WHERE b.tenant_id = v.tenant_id AND b.vehicle_id = v.id AND b.status = 'active'
                          AND b.starts_at <= ? AND b.ends_at > ?
                        ORDER BY b.id LIMIT 1
                    ) AS block_type
                FROM vehicles v
                WHERE v.tenant_id = ? AND v.agency_id IN ($agencies)
                  AND v.created_at <= ? AND v.deleted_at IS NULL
            ) snapshot
        SQL;
        $row = DB::selectOne($sql, [$snapshot, $snapshot, $snapshot, $criteria->tenantId, ...$criteria->agencyIds, $snapshot]);

        return [
            'available' => (int) $row->available,
            'rented' => (int) $row->rented,
            'blocked' => (int) $row->blocked,
            'maintenance' => (int) $row->maintenance,
            'snapshot_at' => $snapshot->toIso8601String(),
        ];
    }

    private function utilizationMetrics(ReportCriteria $criteria): array
    {
        $agencies = $this->placeholders($criteria->agencyIds);
        $sql = <<<SQL
            WITH status_intervals AS (
                SELECT h.vehicle_id, h.to_status,
                    h.created_at AS starts_at,
                    LEAD(h.created_at) OVER (PARTITION BY h.vehicle_id ORDER BY h.created_at, h.id) AS ends_at
                FROM vehicle_status_histories h
                JOIN vehicles v ON v.tenant_id = h.tenant_id AND v.id = h.vehicle_id
                WHERE v.tenant_id = ? AND v.agency_id IN ($agencies) AND v.deleted_at IS NULL
            ), capacity_intervals AS (
                SELECT vehicle_id,
                    GREATEST(starts_at, ?::timestamptz) AS starts_at,
                    LEAST(COALESCE(ends_at, 'infinity'::timestamptz), ?::timestamptz) AS ends_at
                FROM status_intervals
                WHERE to_status IN ('active', 'maintenance')
                  AND starts_at < ?::timestamptz
                  AND COALESCE(ends_at, 'infinity'::timestamptz) > ?::timestamptz
            ), occupied AS (
                SELECT b.block_type,
                    EXTRACT(EPOCH FROM (
                        LEAST(b.ends_at, c.ends_at) - GREATEST(b.starts_at, c.starts_at)
                    ))::bigint AS seconds
                FROM vehicle_blocks b
                JOIN capacity_intervals c ON c.vehicle_id = b.vehicle_id
                  AND b.starts_at < c.ends_at AND b.ends_at > c.starts_at
                WHERE b.tenant_id = ? AND b.agency_id IN ($agencies)
                  AND b.status = 'active'
                  AND b.starts_at < ?::timestamptz AND b.ends_at > ?::timestamptz
            )
            SELECT
                COALESCE((SELECT SUM(EXTRACT(EPOCH FROM (ends_at - starts_at)))::bigint FROM capacity_intervals), 0) AS capacity_seconds,
                COALESCE(SUM(seconds), 0) AS occupied_seconds,
                COALESCE(SUM(seconds) FILTER (WHERE block_type = 'reservation'), 0) AS reservation_seconds,
                COALESCE(SUM(seconds) FILTER (WHERE block_type = 'contract'), 0) AS contract_seconds,
                COALESCE(SUM(seconds) FILTER (WHERE block_type = 'manual'), 0) AS manual_seconds,
                COALESCE(SUM(seconds) FILTER (WHERE block_type = 'maintenance'), 0) AS maintenance_seconds
            FROM occupied
        SQL;
        $bindings = [
            $criteria->tenantId, ...$criteria->agencyIds,
            $criteria->startsAt, $criteria->endsAt, $criteria->endsAt, $criteria->startsAt,
            $criteria->tenantId, ...$criteria->agencyIds,
            $criteria->endsAt, $criteria->startsAt,
        ];
        $row = DB::selectOne($sql, $bindings);
        $capacity = (int) $row->capacity_seconds;
        $occupied = (int) $row->occupied_seconds;
        $rate = $capacity === 0
            ? '0.00'
            : (string) DB::scalar('SELECT ROUND((?::numeric / ?::numeric) * 100, 2)', [$occupied, $capacity]);

        return [
            'rate' => $rate,
            'occupied_seconds' => $occupied,
            'occupied_duration' => $this->duration($occupied),
            'capacity_seconds' => $capacity,
            'block_types' => [
                'reservation' => (int) $row->reservation_seconds,
                'contract' => (int) $row->contract_seconds,
                'manual' => (int) $row->manual_seconds,
                'maintenance' => (int) $row->maintenance_seconds,
            ],
        ];
    }

    private function maintenanceMetrics(ReportCriteria $criteria): array
    {
        $planned = $this->scoped('maintenance_orders', 'm', $criteria)
            ->whereNull('m.deleted_at')
            ->whereIn('m.status', ['planned', 'approved'])
            ->where('m.scheduled_start_at', '>=', $criteria->startsAt)
            ->where('m.scheduled_start_at', '<', $criteria->endsAt)
            ->count();

        $asOf = $criteria->endsAt->lessThan(CarbonImmutable::now($criteria->timezone))
            ? $criteria->endsAt
            : CarbonImmutable::now($criteria->timezone);
        $overdue = $this->scoped('maintenance_orders', 'm', $criteria)
            ->whereNull('m.deleted_at')
            ->whereIn('m.status', ['planned', 'approved'])
            ->whereNotNull('m.scheduled_start_at')
            ->where('m.scheduled_start_at', '<', $asOf)
            ->count();

        $inProgress = $this->scoped('maintenance_orders', 'm', $criteria)
            ->whereNull('m.deleted_at')
            ->whereNotNull('m.actual_start_at')
            ->where('m.actual_start_at', '<', $criteria->endsAt)
            ->where(fn (Builder $query) => $query->whereNull('m.actual_end_at')->orWhere('m.actual_end_at', '>', $criteria->startsAt))
            ->count();

        return ['planned' => $planned, 'overdue' => $overdue, 'in_progress' => $inProgress];
    }

    private function insuranceMetrics(ReportCriteria $criteria): array
    {
        $agencies = $this->placeholders($criteria->agencyIds);
        $sql = <<<SQL
            SELECT COUNT(*) AS aggregate
            FROM insurance_claims c
            WHERE c.tenant_id = ? AND c.agency_id IN ($agencies) AND c.reported_at < ?
              AND COALESCE((
                  SELECT h.to_status FROM insurance_claim_status_histories h
                  WHERE h.tenant_id = c.tenant_id AND h.insurance_claim_id = c.id AND h.changed_at < ?
                  ORDER BY h.changed_at DESC, h.id DESC LIMIT 1
              ), c.status) NOT IN ('rejected', 'closed')
        SQL;
        $open = (int) DB::selectOne($sql, [$criteria->tenantId, ...$criteria->agencyIds, $criteria->endsAt, $criteria->endsAt])->aggregate;

        return ['open_claims' => $open];
    }

    private function expirationMetrics(ReportCriteria $criteria): array
    {
        $localStart = $criteria->startsAt->setTimezone($criteria->timezone)->toDateString();
        $localEnd = $criteria->endsAt->setTimezone($criteria->timezone)->toDateString();
        $documents = $this->scoped('documents', 'd', $criteria)
            ->whereNull('d.deleted_at')
            ->whereNotNull('d.retention_until')
            ->where('d.retention_until', '>=', $localStart)
            ->where('d.retention_until', '<', $localEnd)
            ->count();
        $licences = DB::table('drivers as d')
            ->join('customers as c', fn ($join) => $join->on('c.id', '=', 'd.customer_id')->on('c.tenant_id', '=', 'd.tenant_id'))
            ->where('d.tenant_id', $criteria->tenantId)
            ->whereIn('c.agency_id', $criteria->agencyIds)
            ->whereNull('d.deleted_at')
            ->whereNull('c.deleted_at')
            ->where('d.licence_expires_at', '>=', $localStart)
            ->where('d.licence_expires_at', '<', $localEnd)
            ->count();

        return ['documents' => $documents, 'driving_licences' => $licences, 'total' => $documents + $licences];
    }

    private function financialMetrics(ReportCriteria $criteria): array
    {
        $allocationsAsOf = DB::table('payment_allocations as a')
            ->join('payments as p', fn ($join) => $join->on('p.id', '=', 'a.payment_id')->on('p.tenant_id', '=', 'a.tenant_id'))
            ->where('a.tenant_id', $criteria->tenantId)
            ->whereIn('a.agency_id', $criteria->agencyIds)
            ->whereIn('p.status', ['posted', 'reversed'])
            ->whereNotNull('p.posted_at')
            ->where('p.posted_at', '<', $criteria->endsAt)
            ->groupBy('a.invoice_id')
            ->selectRaw("a.invoice_id, COALESCE(SUM(CASE WHEN p.direction = 'incoming' THEN a.amount ELSE -a.amount END), 0) AS allocated");

        $invoiceRows = $this->scoped('invoices', 'i', $criteria)
            ->leftJoinSub($allocationsAsOf, 'allocated', 'allocated.invoice_id', '=', 'i.id')
            ->whereNull('i.deleted_at')
            ->whereNotNull('i.issued_at')
            ->where('i.issued_at', '>=', $criteria->startsAt)
            ->where('i.issued_at', '<', $criteria->endsAt)
            ->where('i.status', '<>', 'void')
            ->selectRaw('i.currency, COUNT(*) AS issued_invoices, COALESCE(SUM(i.total_amount), 0) AS invoiced_amount, COALESCE(SUM(i.total_amount - COALESCE(allocated.allocated, 0)), 0) AS outstanding_balance')
            ->groupBy('i.currency')
            ->get();

        $collectionRows = DB::table('payment_allocations as a')
            ->join('payments as p', fn ($join) => $join->on('p.id', '=', 'a.payment_id')->on('p.tenant_id', '=', 'a.tenant_id'))
            ->where('a.tenant_id', $criteria->tenantId)
            ->whereIn('a.agency_id', $criteria->agencyIds)
            ->whereIn('p.status', ['posted', 'reversed'])
            ->whereNotNull('p.posted_at')
            ->where('p.posted_at', '>=', $criteria->startsAt)
            ->where('p.posted_at', '<', $criteria->endsAt)
            ->selectRaw("a.currency, COALESCE(SUM(CASE WHEN p.direction = 'incoming' THEN a.amount ELSE -a.amount END), 0) AS collected_net")
            ->groupBy('a.currency')
            ->get();

        $depositRows = $this->scoped('deposit_transactions', 'd', $criteria)
            ->leftJoin('deposit_transactions as original', fn ($join) => $join->on('original.id', '=', 'd.reversal_of_id')->on('original.tenant_id', '=', 'd.tenant_id'))
            ->where('d.occurred_at', '<', $criteria->endsAt)
            ->selectRaw("d.currency,
                COALESCE(SUM(CASE
                    WHEN d.transaction_type IN ('received', 'adjustment_in') THEN d.amount
                    WHEN d.transaction_type IN ('retained', 'refunded', 'adjustment_out') THEN -d.amount
                    WHEN d.transaction_type = 'reversal' AND original.transaction_type IN ('received', 'adjustment_in') THEN -d.amount
                    WHEN d.transaction_type = 'reversal' THEN d.amount ELSE 0 END), 0) AS held_deposits,
                COALESCE(SUM(CASE WHEN d.occurred_at >= ? AND d.transaction_type = 'retained' THEN d.amount
                    WHEN d.occurred_at >= ? AND d.transaction_type = 'reversal' AND original.transaction_type = 'retained' THEN -d.amount ELSE 0 END), 0) AS retained_deposits,
                COALESCE(SUM(CASE WHEN d.occurred_at >= ? AND d.transaction_type = 'refunded' THEN d.amount
                    WHEN d.occurred_at >= ? AND d.transaction_type = 'reversal' AND original.transaction_type = 'refunded' THEN -d.amount ELSE 0 END), 0) AS refunded_deposits",
                [$criteria->startsAt, $criteria->startsAt, $criteria->startsAt, $criteria->startsAt])
            ->groupBy('d.currency')
            ->get();

        $localStart = $criteria->startsAt->setTimezone($criteria->timezone)->toDateString();
        $localEnd = $criteria->endsAt->setTimezone($criteria->timezone)->toDateString();
        $expenseRows = $this->scoped('expenses', 'e', $criteria)
            ->whereNull('e.deleted_at')
            ->where('e.expense_date', '>=', $localStart)
            ->where('e.expense_date', '<', $localEnd)
            ->selectRaw("e.currency,
                COUNT(*) FILTER (WHERE e.status = 'draft') AS draft_count,
                COUNT(*) FILTER (WHERE e.status = 'approved') AS approved_count,
                COUNT(*) FILTER (WHERE e.status = 'rejected') AS rejected_count,
                COALESCE(SUM(e.amount) FILTER (WHERE e.status = 'approved'), 0) AS approved_expenses")
            ->groupBy('e.currency')
            ->get();

        $currencies = [];
        foreach ($invoiceRows as $row) {
            $currency = $row->currency;
            $currencies[$currency] = $this->emptyFinancialCurrency();
            $currencies[$currency]['issued_invoices'] = (int) $row->issued_invoices;
            $currencies[$currency]['invoiced_amount'] = $this->money($row->invoiced_amount);
            $currencies[$currency]['outstanding_balance'] = $this->money($row->outstanding_balance);
        }
        foreach ($collectionRows as $row) {
            $currency = $row->currency;
            $currencies[$currency] ??= $this->emptyFinancialCurrency();
            $currencies[$currency]['collected_net'] = $this->money($row->collected_net);
        }
        foreach ($depositRows as $row) {
            $currency = $row->currency;
            $currencies[$currency] ??= $this->emptyFinancialCurrency();
            $currencies[$currency]['held_deposits'] = $this->money($row->held_deposits);
            $currencies[$currency]['retained_deposits'] = $this->money($row->retained_deposits);
            $currencies[$currency]['refunded_deposits'] = $this->money($row->refunded_deposits);
        }
        foreach ($expenseRows as $row) {
            $currency = $row->currency;
            $currencies[$currency] ??= $this->emptyFinancialCurrency();
            $currencies[$currency]['expenses'] = [
                'draft' => (int) $row->draft_count,
                'approved' => (int) $row->approved_count,
                'rejected' => (int) $row->rejected_count,
            ];
            $currencies[$currency]['approved_expenses'] = $this->money($row->approved_expenses);
        }

        if ($currencies === []) {
            $tenant = Tenant::query()->find($criteria->tenantId);
            $default = strtoupper((string) ($tenant?->settings['currency'] ?? 'MAD'));
            $currencies[$default] = $this->emptyFinancialCurrency();
        }
        if ($criteria->currency !== null) {
            $currencies[$criteria->currency] ??= $this->emptyFinancialCurrency();
        }
        ksort($currencies);

        $visible = $criteria->currency === null
            ? $currencies
            : [$criteria->currency => $currencies[$criteria->currency] ?? $this->emptyFinancialCurrency()];

        return ['all_currencies' => $currencies, 'visible_currencies' => $visible];
    }

    private function scoped(string $table, string $alias, ReportCriteria $criteria): Builder
    {
        return DB::table($table.' as '.$alias)
            ->where($alias.'.tenant_id', $criteria->tenantId)
            ->whereIn($alias.'.agency_id', $criteria->agencyIds);
    }

    private function assertCriteria(ReportCriteria $criteria): void
    {
        if (! $this->context->hasTenant() || $this->context->tenantId() !== $criteria->tenantId) {
            throw new AuthorizationException('Le tenant du rapport ne correspond pas au contexte actif.');
        }

        if ($this->context->agencyId() !== null && $criteria->agencyIds !== [$this->context->agencyId()]) {
            throw new AuthorizationException('Le rapport dépasse le périmètre de l’agence active.');
        }

        $authorized = DB::table('agencies')
            ->where('tenant_id', $criteria->tenantId)
            ->whereIn('id', $criteria->agencyIds)
            ->count();
        if ($authorized !== count($criteria->agencyIds)) {
            throw new AuthorizationException('Une agence du rapport ne correspond pas au tenant actif.');
        }
    }

    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    private function emptyFinancialCurrency(): array
    {
        return [
            'issued_invoices' => 0,
            'invoiced_amount' => '0.00',
            'collected_net' => '0.00',
            'outstanding_balance' => '0.00',
            'held_deposits' => '0.00',
            'retained_deposits' => '0.00',
            'refunded_deposits' => '0.00',
            'expenses' => ['draft' => 0, 'approved' => 0, 'rejected' => 0],
            'approved_expenses' => '0.00',
        ];
    }

    private function money(string|int|null $amount): string
    {
        return DecimalMoney::fromMinorUnits(DecimalMoney::toMinorUnits((string) ($amount ?? 0)));
    }

    private function duration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return sprintf('%d h %02d min', $hours, $minutes);
    }
}
