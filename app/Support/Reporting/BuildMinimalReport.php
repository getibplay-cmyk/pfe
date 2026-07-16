<?php

namespace App\Support\Reporting;

use App\Enums\RentalContractStatus;
use App\Enums\ReservationStatus;
use App\Models\Expense;
use App\Models\InsuranceClaim;
use App\Models\Invoice;
use App\Models\MaintenanceOrder;
use App\Models\RentalContract;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Models\VehicleBlock;
use App\Support\Pricing\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class BuildMinimalReport
{
    public function handle(CarbonImmutable $from, CarbonImmutable $until, ?int $agencyId, int $tenantId): array
    {
        $agency = fn (Builder $query): Builder => $query->when($agencyId, fn (Builder $builder) => $builder->where('agency_id', $agencyId));
        $reservationBase = $agency(Reservation::query())->where('starts_at', '<', $until)->where('ends_at', '>=', $from);
        $contractBase = $agency(RentalContract::query())->where('expected_start_at', '<', $until)->where('expected_return_at', '>=', $from);
        $vehicleBase = $agency(Vehicle::query());

        $reservationsByStatus = $reservationBase->clone()->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $contractsByStatus = $contractBase->clone()->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $availableVehicles = $vehicleBase->clone()->where('operational_status', 'active')->whereDoesntHave('blocks', fn ($query) => $query
            ->where('status', 'active')->where('starts_at', '<=', now())->where('ends_at', '>', now()))->count();
        $rentedVehicles = $agency(RentalContract::query())->whereIn('status', ['active', 'return_pending'])->distinct('vehicle_id')->count('vehicle_id');
        $maintenanceVehicles = $vehicleBase->clone()->where('operational_status', 'maintenance')->count();
        $fleetSize = $vehicleBase->clone()->whereNotIn('operational_status', ['out_of_service', 'archived'])->count();

        $occupiedSeconds = $agency(VehicleBlock::query())
            ->whereIn('block_type', ['reservation', 'contract'])
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', $until)
            ->where('ends_at', '>', $from)
            ->selectRaw('COALESCE(SUM(EXTRACT(EPOCH FROM (LEAST(ends_at, ?) - GREATEST(starts_at, ?)))), 0) AS aggregate', [$until, $from])
            ->value('aggregate');
        $capacitySeconds = $fleetSize * ($until->getTimestamp() - $from->getTimestamp());
        $utilizationRate = $capacitySeconds === 0
            ? '0.00'
            : (string) DB::selectOne('SELECT ROUND((?::numeric / ?::numeric) * 100, 2) AS rate', [(string) $occupiedSeconds, (string) $capacitySeconds])->rate;

        $maintenanceBase = $agency(MaintenanceOrder::query())->whereIn('status', ['planned', 'approved', 'in_progress']);
        $openClaims = $agency(InsuranceClaim::query())->whereNotIn('status', ['rejected', 'closed'])->count();

        $invoices = $agency(Invoice::query())->whereNotNull('issued_at')->where('issued_at', '>=', $from)->where('issued_at', '<', $until);
        $allocationQuery = DB::table('payment_allocations as a')
            ->join('payments as p', fn ($join) => $join->on('p.id', '=', 'a.payment_id')->on('p.tenant_id', '=', 'a.tenant_id'))
            ->where('a.tenant_id', $tenantId)
            ->when($agencyId, fn ($query) => $query->where('a.agency_id', $agencyId))
            ->whereIn('p.status', ['posted', 'reversed'])
            ->where('p.paid_at', '>=', $from)->where('p.paid_at', '<', $until);
        $allocated = $allocationQuery->selectRaw("COALESCE(SUM(CASE WHEN p.direction = 'incoming' THEN a.amount ELSE -a.amount END), 0) AS aggregate")->value('aggregate');
        $heldDeposits = DB::table('deposit_transactions as d')
            ->leftJoin('deposit_transactions as original', 'original.id', '=', 'd.reversal_of_id')
            ->where('d.tenant_id', $tenantId)
            ->when($agencyId, fn ($query) => $query->where('d.agency_id', $agencyId))
            ->where('d.occurred_at', '<', $until)
            ->selectRaw("COALESCE(SUM(CASE
                WHEN d.transaction_type IN ('received', 'adjustment_in') THEN d.amount
                WHEN d.transaction_type IN ('retained', 'refunded', 'adjustment_out') THEN -d.amount
                WHEN d.transaction_type = 'reversal' AND original.transaction_type IN ('received', 'adjustment_in') THEN -d.amount
                WHEN d.transaction_type = 'reversal' THEN d.amount
                ELSE 0 END), 0) AS aggregate")
            ->value('aggregate');
        $expenses = $agency(Expense::query())->where('status', 'approved')->whereDate('expense_date', '>=', $from->toDateString())->whereDate('expense_date', '<', $until->toDateString());

        return [
            'operational' => [
                'reservations' => collect(ReservationStatus::cases())->mapWithKeys(fn ($status) => [$status->label() => (int) ($reservationsByStatus[$status->value] ?? 0)]),
                'contracts' => collect(RentalContractStatus::cases())->mapWithKeys(fn ($status) => [$status->label() => (int) ($contractsByStatus[$status->value] ?? 0)]),
                'vehicles' => ['Disponibles maintenant' => $availableVehicles, 'Loués' => $rentedVehicles, 'En maintenance' => $maintenanceVehicles],
                'utilization_rate' => $utilizationRate,
                'maintenance_upcoming' => $maintenanceBase->clone()->where('scheduled_start_at', '>=', now())->where('scheduled_start_at', '<=', now()->addDays(30))->count(),
                'maintenance_overdue' => $maintenanceBase->clone()->where('scheduled_start_at', '<', now())->count(),
                'open_claims' => $openClaims,
            ],
            'financial' => [
                'issued_invoices' => $invoices->clone()->count(),
                'invoiced_amount' => $this->money($invoices->clone()->sum('total_amount')),
                'allocated_collections' => $this->money($allocated),
                'outstanding_balance' => $this->money($invoices->clone()->sum('balance_due')),
                'held_deposits' => $this->money($heldDeposits),
                'approved_expenses' => $this->money($expenses->sum('amount')),
            ],
        ];
    }

    private function money(string|int|null $amount): string
    {
        return DecimalMoney::fromMinorUnits(DecimalMoney::toMinorUnits((string) ($amount ?? 0)));
    }
}
