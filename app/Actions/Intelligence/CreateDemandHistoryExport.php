<?php

namespace App\Actions\Intelligence;

use App\Models\DemandHistoryExportRun;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\DemandForecasting\DemandForecastContract;
use App\Support\Intelligence\IntelligencePseudonymizer;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class CreateDemandHistoryExport
{
    public function __construct(
        private readonly IntelligencePseudonymizer $pseudonymizer,
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(
        int $agencyId,
        string $dateFrom,
        string $dateTo,
        User $actor,
    ): DemandHistoryExportRun {
        $this->assertExportAuthorized($agencyId, $actor);

        return $this->create($agencyId, $dateFrom, $dateTo, $actor);
    }

    public function handleForForecast(int $agencyId, User $actor): DemandHistoryExportRun
    {
        $this->assertForecastAuthorized($agencyId, $actor);
        $to = CarbonImmutable::now(DemandForecastContract::TIMEZONE)->startOfDay();

        return $this->create(
            $agencyId,
            $to->subDays(179)->toDateString(),
            $to->toDateString(),
            $actor,
        );
    }

    private function create(
        int $agencyId,
        string $dateFrom,
        string $dateTo,
        User $actor,
    ): DemandHistoryExportRun {

        $from = CarbonImmutable::createFromFormat('!Y-m-d', $dateFrom, DemandForecastContract::TIMEZONE);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $dateTo, DemandForecastContract::TIMEZONE);
        if ($from === false
            || $to === false
            || $from->format('Y-m-d') !== $dateFrom
            || $to->format('Y-m-d') !== $dateTo
            || $from->greaterThan($to)) {
            throw new RuntimeException('La période de demande est invalide.');
        }

        $rowCount = (int) $from->diffInDays($to) + 1;
        if ($rowCount < DemandForecastContract::MINIMUM_HISTORY_DAYS
            || $rowCount > DemandForecastContract::MAXIMUM_HISTORY_DAYS) {
            throw new RuntimeException('La période de demande doit contenir entre 35 et 731 jours.');
        }

        $runId = (string) Str::uuid();
        $storedPath = 'intelligence/demand-history/'.$runId.'.csv';
        $originalName = 'rentfleet_demand_history_'.$runId.'.csv';
        $stream = tmpfile();
        if ($stream === false) {
            throw new RuntimeException('Impossible de préparer le snapshot de demande privé.');
        }

        $disk = Storage::disk((string) config('intelligence.demand_forecasting.disk'));

        try {
            $tenantId = $this->context->tenantId();
            $agencyKey = $this->pseudonymizer->agencyKey($tenantId, $agencyId);
            $seriesKey = $this->pseudonymizer->demandSeriesKey($tenantId, $agencyId);
            $tenantKey = $this->pseudonymizer->tenantKey($tenantId);
            $departureCounts = $this->departureCounts($tenantId, $agencyId, $from, $to);
            $departuresTotal = $this->writeSnapshot(
                $stream,
                $from,
                $to,
                $tenantKey,
                $agencyKey,
                $seriesKey,
                $departureCounts,
            );
            $metadata = $this->snapshotMetadata($stream);
            rewind($stream);

            if (! $disk->writeStream($storedPath, $stream)) {
                throw new RuntimeException('Impossible de conserver le snapshot de demande privé.');
            }

            return DB::transaction(function () use (
                $agencyId,
                $actor,
                $from,
                $to,
                $rowCount,
                $runId,
                $storedPath,
                $originalName,
                $agencyKey,
                $seriesKey,
                $departuresTotal,
                $metadata,
            ): DemandHistoryExportRun {
                $run = DemandHistoryExportRun::create([
                    'agency_id' => $agencyId,
                    'run_id' => $runId,
                    'manifest_version' => DemandForecastContract::MANIFEST_VERSION,
                    'schema_version' => DemandForecastContract::DATASET_SCHEMA_VERSION,
                    'dataset_version' => DemandForecastContract::DATASET_VERSION,
                    'preprocessing_version' => DemandForecastContract::PREPROCESSING_VERSION,
                    'target_semantics' => DemandForecastContract::TARGET,
                    'vehicle_category_scope' => DemandForecastContract::VEHICLE_CATEGORY_SCOPE,
                    'timezone' => DemandForecastContract::TIMEZONE,
                    'distance_unit' => DemandForecastContract::DISTANCE_UNIT,
                    'agency_key' => $agencyKey,
                    'series_key' => $seriesKey,
                    'date_from' => $from->toDateString(),
                    'date_to' => $to->toDateString(),
                    'row_count' => $rowCount,
                    'max_rows' => DemandForecastContract::MAXIMUM_HISTORY_DAYS,
                    'observed_departures_count' => $departuresTotal,
                    'content_sha256' => $metadata['sha256'],
                    'byte_size' => $metadata['bytes'],
                    'format' => 'csv',
                    'stored_path' => $storedPath,
                    'original_name' => $originalName,
                    'operational_effect' => DemandForecastContract::OPERATIONAL_EFFECT,
                    'created_by' => $actor->id,
                    'created_at' => now(),
                ]);

                $this->audit->record('prediction.demand_history.exported', $run, [], [
                    'run_id' => $run->run_id,
                    'schema_version' => $run->schema_version,
                    'dataset_version' => $run->dataset_version,
                    'date_from' => $run->date_from->toDateString(),
                    'date_to' => $run->date_to->toDateString(),
                    'row_count' => $run->row_count,
                    'observed_departures_count' => $run->observed_departures_count,
                    'distance_unit' => $run->distance_unit,
                    'operational_effect' => $run->operational_effect,
                ]);

                return $run;
            }, 3);
        } catch (Throwable $exception) {
            $disk->delete($storedPath);

            throw $exception;
        } finally {
            fclose($stream);
        }
    }

    /** @return array<string, int> */
    private function departureCounts(
        int $tenantId,
        int $agencyId,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $startsAt = $from->startOfDay()->utc();
        $endsAt = $to->addDay()->startOfDay()->utc();

        return DB::table('rental_contracts')
            ->where('tenant_id', $tenantId)
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereNotNull('actual_start_at')
            ->whereIn('status', ['active', 'return_pending', 'returned', 'closed'])
            ->where('actual_start_at', '>=', $startsAt)
            ->where('actual_start_at', '<', $endsAt)
            ->selectRaw(
                '(actual_start_at AT TIME ZONE ?)::date AS departure_date, COUNT(*)::integer AS departure_count',
                [DemandForecastContract::TIMEZONE],
            )
            ->groupBy('departure_date')
            ->orderBy('departure_date')
            ->get()
            ->mapWithKeys(static fn (object $row): array => [
                (string) $row->departure_date => (int) $row->departure_count,
            ])
            ->all();
    }

    /**
     * @param  resource  $stream
     * @param  array<string, int>  $departureCounts
     */
    private function writeSnapshot(
        $stream,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $tenantKey,
        string $agencyKey,
        string $seriesKey,
        array $departureCounts,
    ): int {
        if (fwrite($stream, "\xEF\xBB\xBF") !== 3
            || fputcsv($stream, DemandForecastContract::snapshotHeaders(), ';', '"', '', "\n") === false) {
            throw new RuntimeException('Impossible de générer le snapshot de demande.');
        }

        $departuresTotal = 0;
        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $dateLocal = $date->toDateString();
            $departures = $departureCounts[$dateLocal] ?? 0;
            $departuresTotal += $departures;
            $row = [
                DemandForecastContract::DATASET_SCHEMA_VERSION,
                DemandForecastContract::DATASET_VERSION,
                DemandForecastContract::PREPROCESSING_VERSION,
                $seriesKey,
                $tenantKey,
                $agencyKey,
                DemandForecastContract::VEHICLE_CATEGORY_SCOPE,
                $dateLocal,
                (string) $departures,
                '1',
                DemandForecastContract::TIMEZONE,
                DemandForecastContract::DISTANCE_UNIT,
            ];
            if (fputcsv($stream, $row, ';', '"', '', "\n") === false) {
                throw new RuntimeException('Impossible de générer une ligne du snapshot de demande.');
            }
        }

        return $departuresTotal;
    }

    /** @param resource $stream @return array{sha256: string, bytes: int} */
    private function snapshotMetadata($stream): array
    {
        fflush($stream);
        $stats = fstat($stream);
        if ($stats === false || ! isset($stats['size']) || $stats['size'] <= 0) {
            throw new RuntimeException('Le snapshot de demande généré est vide.');
        }

        rewind($stream);
        $hash = hash_init('sha256');
        hash_update_stream($hash, $stream);

        return ['sha256' => hash_final($hash), 'bytes' => (int) $stats['size']];
    }

    private function assertExportAuthorized(int $agencyId, User $actor): void
    {
        if ($actor->tenant_id !== $this->context->tenantId()
            || ! $actor->hasPermission('prediction.export')
            || ! $this->pseudonymizer->configured()
            || ($actor->agency_id !== null && $actor->agency_id !== $agencyId)) {
            throw new AuthorizationException;
        }
    }

    private function assertForecastAuthorized(int $agencyId, User $actor): void
    {
        if ($actor->tenant_id !== $this->context->tenantId()
            || ! $actor->is_active
            || ! $actor->hasPermission('prediction.view')
            || ! $actor->hasPermission('prediction.forecast.import')
            || ! $this->pseudonymizer->configured()
            || ($actor->agency_id !== null && $actor->agency_id !== $agencyId)) {
            throw new AuthorizationException;
        }
    }
}
