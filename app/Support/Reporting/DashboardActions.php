<?php

namespace App\Support\Reporting;

use App\Models\Invoice;
use App\Models\MaintenanceOrder;
use App\Models\RentalContract;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Ui\UiLabel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class DashboardActions
{
    public function for(User $user, ?string $only = null): array
    {
        $context = app(TenantContext::class);
        abort_unless((int) $user->tenant_id === $context->tenantId(), 403);
        $timezone = (string) ($user->tenant->settings['timezone'] ?? config('app.timezone'));
        $now = CarbonImmutable::now($timezone);
        $today = $now->startOfDay();
        $tomorrow = $today->addDay();
        $scope = fn (Builder $query) => $query->when($context->agencyId(), fn ($query, $id) => $query->where('agency_id', $id));
        $groups = [];
        $add = function (string $key, string $title, Builder $query, callable $row) use (&$groups, $only): void {
            if ($only !== null && $only !== $key) {
                return;
            }
            $count = (clone $query)->count();
            $items = $only === null
                ? $query->limit(5)->get()->map($row)
                : $query->orderBy('id')->paginate(25)->withQueryString()->through($row);
            $groups[] = [
                'key' => $key, 'title' => $title, 'count' => $count,
                'items' => $items, 'url' => route('dashboard.actions', $key),
            ];
        };
        if ($user->hasPermission('contract.view')) {
            $query = $scope(RentalContract::query())->with('vehicle:id,registration_number')->whereIn('status', ['active', 'return_pending']);
            $row = fn ($contract) => ['label' => $contract->contract_number.' · '.$contract->vehicle?->registration_number,
                'detail' => UiLabel::dateTime($contract->expected_return_at), 'url' => route('contracts.show', $contract), 'action' => 'Traiter le retour'];
            $add('overdue', 'Retours en retard', (clone $query)->where('expected_return_at', '<', $now)->orderBy('expected_return_at'), $row);
            $add('returns', 'Retours à venir aujourd’hui', (clone $query)->where('expected_return_at', '>=', $now)->where('expected_return_at', '<', $tomorrow)->orderBy('expected_return_at'), $row);
            $add('departures', 'Contrats à préparer aujourd’hui', $scope(RentalContract::query())->whereIn('status', ['draft', 'ready', 'accepted'])
                ->where('expected_start_at', '>=', $today)->where('expected_start_at', '<', $tomorrow)->orderBy('expected_start_at'),
                fn ($contract) => ['label' => $contract->contract_number, 'detail' => UiLabel::dateTime($contract->expected_start_at), 'url' => route('contracts.show', $contract), 'action' => 'Préparer le départ']);
        }
        if ($user->hasPermission('reservation.view')) {
            $add('reservations', 'Réservations à préparer aujourd’hui', $scope(Reservation::query())->where('status', 'confirmed')
                ->where('starts_at', '>=', $today)->where('starts_at', '<', $tomorrow)->orderBy('starts_at'),
                fn ($reservation) => ['label' => $reservation->reservation_number, 'detail' => UiLabel::dateTime($reservation->starts_at), 'url' => route('reservations.show', $reservation), 'action' => 'Ouvrir la réservation']);
        }
        if ($user->hasPermission('invoice.view')) {
            $add('invoices', 'Factures échues à encaisser', $scope(Invoice::query())->whereIn('status', ['issued', 'partially_paid'])
                ->where('balance_due', '>', 0)->where('due_at', '<=', $now)->orderBy('due_at'),
                fn ($invoice) => ['label' => $invoice->invoice_number, 'detail' => UiLabel::money($invoice->balance_due, $invoice->currency), 'url' => route('finance.invoices.show', $invoice), 'action' => 'Consulter le solde']);
        }
        if ($user->hasPermission('maintenance.view')) {
            $add('maintenance', 'Interventions à démarrer', $scope(MaintenanceOrder::query())->whereIn('status', ['planned', 'approved'])
                ->where('scheduled_start_at', '<', $tomorrow)->orderBy('scheduled_start_at'),
                fn ($order) => ['label' => $order->maintenance_number, 'detail' => UiLabel::dateTime($order->scheduled_start_at), 'url' => route('maintenance.show', $order), 'action' => 'Ouvrir l’intervention']);
        }

        abort_if($only !== null && $groups === [], 404);

        return $groups;
    }
}
