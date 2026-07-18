<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReservationExportRequest;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Support\Audit\AuditRecorder;
use App\Support\Export\SpreadsheetSafeCsv;
use App\Support\Reporting\ResolveReportCriteria;
use App\Support\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReservationExportController extends Controller
{
    private const MAX_ROWS = 10000;

    public function __invoke(
        ReservationExportRequest $request,
        ResolveReportCriteria $resolver,
        TenantContext $context,
        AuditRecorder $audit,
    ): StreamedResponse {
        $data = $request->validated();
        $criteria = $resolver->handle($data);
        $query = Reservation::query()
            ->with(['agency', 'customer', 'vehicleCategory', 'vehicle'])
            ->where('tenant_id', $criteria->tenantId)
            ->whereIn('agency_id', $criteria->agencyIds)
            ->where('starts_at', '<', $criteria->endsAt)
            ->where('ends_at', '>', $criteria->startsAt)
            ->when($data['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status))
            ->when($data['vehicle_category_id'] ?? null, fn ($builder, $category) => $builder->where('vehicle_category_id', $category))
            ->when($data['vehicle_id'] ?? null, fn ($builder, $vehicle) => $builder->where('vehicle_id', $vehicle));
        $filename = sprintf('reservations_%s_%s.csv', $criteria->dateFrom(), $criteria->dateTo());
        $tenant = Tenant::query()->findOrFail($criteria->tenantId);
        $contextAgencyId = $context->agencyId();

        $audit->record('reservation.exported', $tenant, [], [
            'date_from' => $criteria->dateFrom(),
            'date_to' => $criteria->dateTo(),
            'agency_ids' => $criteria->agencyIds,
            'status' => $data['status'] ?? null,
            'vehicle_category_id' => $data['vehicle_category_id'] ?? null,
            'vehicle_id' => $data['vehicle_id'] ?? null,
            'max_rows' => self::MAX_ROWS,
        ]);

        return response()->streamDownload(function () use ($query, $context, $criteria, $contextAgencyId): void {
            echo "\xEF\xBB\xBF";
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }
            fputcsv($output, ['Numéro', 'Agence', 'Statut', 'Début', 'Fin', 'Catégorie', 'Véhicule', 'Client', 'Montant', 'Devise'], ';');

            $context->run($criteria->tenantId, function () use ($query, $output, $criteria): void {
                $rows = 0;
                foreach ($query->lazyById(500) as $reservation) {
                    if ($rows++ >= self::MAX_ROWS) {
                        break;
                    }
                    $row = [
                        $reservation->reservation_number,
                        $reservation->agency->name,
                        $reservation->status->label(),
                        $reservation->starts_at->timezone($criteria->timezone)->format('Y-m-d H:i:sP'),
                        $reservation->ends_at->timezone($criteria->timezone)->format('Y-m-d H:i:sP'),
                        $reservation->vehicleCategory->name,
                        $reservation->vehicle?->registration_number ?? 'Non affecté',
                        $reservation->customer->displayName(),
                        $reservation->total_amount,
                        $reservation->currency,
                    ];
                    fputcsv($output, array_map(SpreadsheetSafeCsv::cell(...), $row), ';');
                }
            }, $contextAgencyId);

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
