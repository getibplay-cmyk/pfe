<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Driver;
use App\Models\InsuranceClaim;
use App\Models\Invoice;
use App\Models\MaintenanceOrder;
use App\Models\RentalContract;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Support\Ui\UiLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $agencyId = $user->agency_id;
        $scope = fn (Builder $query): Builder => $query->when($agencyId, fn (Builder $builder) => $builder->where('agency_id', $agencyId));
        $now = now();
        $soon = $now->copy()->addDays(30);
        $kpis = [];
        $currency = $user->tenant?->settings['currency'] ?? 'MAD';
        $maintenanceSummary = null;

        if ($user->hasPermission('vehicle.view')) {
            $kpis['Véhicules opérationnels'] = $scope(Vehicle::query())->where('operational_status', 'active')->count();
            $kpis['Véhicules indisponibles'] = $scope(Vehicle::query())->whereIn('operational_status', ['maintenance', 'out_of_service'])->count();
        }
        if ($user->hasPermission('reservation.view')) {
            $kpis['Réservations confirmées'] = $scope(Reservation::query())->where('status', 'confirmed')->count();
            $kpis['Départs dans les 7 jours'] = $scope(Reservation::query())->where('status', 'confirmed')->whereBetween('starts_at', [$now, $now->copy()->addDays(7)])->count();
        }
        if ($user->hasPermission('contract.view')) {
            $kpis['Retours à traiter'] = $scope(RentalContract::query())->whereIn('status', ['active', 'return_pending'])->where('expected_return_at', '<=', $now->copy()->addDays(7))->count();
        }
        if ($user->hasPermission('invoice.view')) {
            $invoices = $scope(Invoice::query())->whereIn('status', ['issued', 'partially_paid']);
            $kpis['Factures impayées'] = (clone $invoices)->count();
            $kpis['Solde client à recevoir'] = UiLabel::money((string) (clone $invoices)->sum('balance_due'), $currency);
        }
        if ($user->hasPermission('maintenance.view')) {
            $maintenanceSummary = [
                'Planifiées à venir' => $scope(MaintenanceOrder::query())->whereIn('status', ['planned', 'approved'])->whereBetween('scheduled_start_at', [$now, $soon])->count(),
                'En retard' => $scope(MaintenanceOrder::query())->whereIn('status', ['planned', 'approved'])->where('scheduled_start_at', '<', $now)->count(),
                'En cours' => $scope(MaintenanceOrder::query())->where('status', 'in_progress')->count(),
                'Échéances kilométriques' => $scope(MaintenanceOrder::query())->whereNotNull('next_due_mileage')->whereHas('vehicle', fn (Builder $query) => $query->whereColumn('vehicles.current_mileage', '>=', 'maintenance_orders.next_due_mileage'))->count(),
                'Échéances calendaires' => $scope(MaintenanceOrder::query())->whereNotNull('next_due_date')->whereDate('next_due_date', '<=', $soon)->count(),
            ];
        }
        if ($user->hasPermission('claim.view')) {
            $kpis['Sinistres ouverts'] = $scope(InsuranceClaim::query())->whereNotIn('status', ['rejected', 'closed'])->count();
        }

        return view('dashboard', [
            'kpis' => $kpis,
            'maintenanceSummary' => $maintenanceSummary,
            'recentActivity' => $user->hasPermission('audit.view')
                ? $scope(AuditLog::query())->with('user:id,name')->latest('created_at')->limit(8)->get()
                : null,
            'upcomingReservations' => $user->hasPermission('reservation.view')
                ? $scope(Reservation::query())->with(['agency:id,name', 'customer:id,customer_type,first_name,last_name,company_name', 'vehicle:id,registration_number'])->whereIn('status', ['pending', 'confirmed'])->whereBetween('starts_at', [$now, $soon])->orderBy('starts_at')->limit(6)->get()
                : null,
            'expectedReturns' => $user->hasPermission('contract.view')
                ? $scope(RentalContract::query())->with(['customer:id,customer_type,first_name,last_name,company_name', 'vehicle:id,registration_number'])->whereIn('status', ['active', 'return_pending'])->where('expected_return_at', '<=', $now->copy()->addDays(7))->orderBy('expected_return_at')->limit(6)->get()
                : null,
            'unavailableVehicles' => $user->hasPermission('vehicle.view')
                ? $scope(Vehicle::query())->with('agency:id,name')->whereIn('operational_status', ['maintenance', 'out_of_service'])->orderBy('registration_number')->limit(6)->get()
                : null,
            'expiringDocuments' => $user->hasPermission('document.view')
                ? $scope(Document::query())->whereNotNull('retention_until')->whereDate('retention_until', '<=', $soon)->orderBy('retention_until')->limit(6)->get()
                : null,
            'expiringLicences' => $user->hasPermission('customer.view')
                ? Driver::query()->with('customer:id,agency_id,customer_type,first_name,last_name,company_name')->whereHas('customer', fn (Builder $query) => $query->when($agencyId, fn (Builder $builder) => $builder->where('agency_id', $agencyId)))->whereDate('licence_expires_at', '<=', $soon)->orderBy('licence_expires_at')->limit(6)->get()
                : null,
            'unpaidInvoices' => $user->hasPermission('invoice.view')
                ? $scope(Invoice::query())->with('rentalContract:id,contract_number')->whereIn('status', ['issued', 'partially_paid'])->orderByRaw('due_at ASC NULLS LAST')->limit(6)->get()
                : null,
            'upcomingMaintenance' => $user->hasPermission('maintenance.view')
                ? $scope(MaintenanceOrder::query())->with('vehicle:id,registration_number')->whereNotIn('status', ['completed', 'cancelled'])->where(fn (Builder $query) => $query->where(fn (Builder $scheduled) => $scheduled->whereNotNull('scheduled_start_at')->where('scheduled_start_at', '<=', $soon))->orWhere(fn (Builder $due) => $due->whereNotNull('next_due_date')->whereDate('next_due_date', '<=', $soon)))->orderByRaw('scheduled_start_at ASC NULLS LAST')->limit(6)->get()
                : null,
            'openClaims' => $user->hasPermission('claim.view')
                ? $scope(InsuranceClaim::query())->with('policy:id,vehicle_id')->whereNotIn('status', ['rejected', 'closed'])->latest('reported_at')->limit(6)->get()
                : null,
        ]);
    }
}
