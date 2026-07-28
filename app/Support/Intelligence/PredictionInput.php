<?php

namespace App\Support\Intelligence;

final readonly class PredictionInput
{
    public const DATASET_VERSION = 'rentfleet-real-returns-v1.1.0';

    public const SCHEMA_VERSION = '1.1';

    public function __construct(
        public string $schemaVersion,
        public string $datasetVersion,
        public string $rowId,
        public string $tenantKey,
        public string $agencyKey,
        public string $contractKey,
        public string $eventAt,
        public string $lateHours,
        public string $kmPerDay,
        public string $fuelDropPct,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toExportRow(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'dataset_version' => $this->datasetVersion,
            'row_id' => $this->rowId,
            'tenant_key' => $this->tenantKey,
            'agency_key' => $this->agencyKey,
            'contract_key' => $this->contractKey,
            'event_at' => $this->eventAt,
            'late_hours' => $this->lateHours,
            'km_per_day' => $this->kmPerDay,
            'fuel_drop_pct' => $this->fuelDropPct,
        ];
    }

    /**
     * @return list<string>
     */
    public static function headers(): array
    {
        return [
            'schema_version',
            'dataset_version',
            'row_id',
            'tenant_key',
            'agency_key',
            'contract_key',
            'event_at',
            'late_hours',
            'km_per_day',
            'fuel_drop_pct',
        ];
    }
}
