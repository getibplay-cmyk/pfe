<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReservationExportRequest;
use App\Models\Reservation;
use App\Support\Export\SpreadsheetSafeCsv;
use App\Support\Tenancy\AgencyAccess;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReservationExportController extends Controller
{
    public function __invoke(ReservationExportRequest $request, AgencyAccess $access, TenantContext $context): StreamedResponse
    {
        $data = $request->validated();
        $agencyId = $context->agencyId() !== null
            ? $access->required($data['agency_id'] ?? $context->agencyId())
            : $access->optional($data['agency_id'] ?? null);
        $from = CarbonImmutable::parse($data['date_from'])->startOfDay();
        $until = CarbonImmutable::parse($data['date_to'])->addDay()->startOfDay();

        $query = Reservation::query()
            ->with(['agency', 'customer', 'vehicleCategory', 'vehicle'])
            ->where('starts_at', '<', $until)
            ->where('ends_at', '>=', $from)
            ->when($agencyId, fn ($builder) => $builder->where('agency_id', $agencyId))
            ->when($data['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status))
            ->when($data['vehicle_category_id'] ?? null, fn ($builder, $category) => $builder->where('vehicle_category_id', $category))
            ->when($data['vehicle_id'] ?? null, fn ($builder, $vehicle) => $builder->where('vehicle_id', $vehicle))
            ->orderBy('id');
        $filename = sprintf('reservations_%s_%s.csv', $from->toDateString(), $until->subDay()->toDateString());
        $tenantId = $context->tenantId();

        return response()->streamDownload(function () use ($query, $context, $tenantId, $agencyId): void {
            echo "\xEF\xBB\xBF";
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['Numéro', 'Agence', 'Statut', 'Début', 'Fin', 'Catégorie', 'Véhicule', 'Client', 'Montant', 'Devise'], ';');

            $context->run($tenantId, function () use ($query, $output): void {
                foreach ($query->lazyById(500) as $reservation) {
                    $row = [
                        $reservation->reservation_number,
                        $reservation->agency->name,
                        $reservation->status->label(),
                        $reservation->starts_at->format('Y-m-d H:i:sP'),
                        $reservation->ends_at->format('Y-m-d H:i:sP'),
                        $reservation->vehicleCategory->name,
                        $reservation->vehicle?->registration_number ?? 'Non affecté',
                        $reservation->customer->displayName(),
                        $reservation->total_amount,
                        $reservation->currency,
                    ];
                    fputcsv($output, array_map(SpreadsheetSafeCsv::cell(...), $row), ';');
                }
            }, $agencyId);

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
