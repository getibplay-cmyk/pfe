<?php

namespace App\Support\Intelligence\RentalUsageAnomaly;

use App\Exceptions\RentalUsageAnomalyExecutionException;
use App\Models\Agency;
use App\Models\IntelligenceDatasetExportRun;
use App\Models\RentalContract;
use App\Support\Intelligence\IntelligencePseudonymizer;
use App\Support\Intelligence\RentalAnomalyDataset;
use App\Support\Reporting\ReportCriteria;

final class ResolveRentalUsageAnomalyContracts
{
    public function __construct(
        private readonly RentalAnomalyDataset $dataset,
        private readonly IntelligencePseudonymizer $pseudonymizer,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, RentalContract>
     */
    public function resolve(IntelligenceDatasetExportRun $export, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $wanted = collect($rows)->pluck('contract_key')->flip()->all();
        $agencies = $export->agency_id === null
            ? Agency::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all()
            : [(int) $export->agency_id];
        if ($agencies === []) {
            throw new RentalUsageAnomalyExecutionException('SOURCE_CONTRACT_UNAVAILABLE');
        }
        $criteria = ReportCriteria::fromInclusiveDates(
            (int) $export->tenant_id,
            $agencies,
            $export->date_from->toDateString(),
            $export->date_to->toDateString(),
            $export->timezone,
        );

        $resolved = [];
        $query = $this->dataset->query($criteria)->select(['id', 'tenant_id', 'agency_id']);
        foreach ($query->lazyById(500) as $contract) {
            $key = $this->pseudonymizer->contractKey((int) $contract->tenant_id, (int) $contract->id);
            if (! isset($wanted[$key])) {
                continue;
            }
            $resolved[$key] = $contract;
            if (count($resolved) === count($wanted)) {
                break;
            }
        }
        if (count($resolved) !== count($wanted)) {
            throw new RentalUsageAnomalyExecutionException('SOURCE_CONTRACT_UNAVAILABLE');
        }
        foreach ($rows as $row) {
            $contract = $resolved[$row['contract_key']];
            if (! hash_equals(
                $row['agency_key'],
                $this->pseudonymizer->agencyKey((int) $contract->tenant_id, (int) $contract->agency_id),
            )) {
                throw new RentalUsageAnomalyExecutionException('SOURCE_CONTRACT_UNAVAILABLE');
            }
        }

        return $resolved;
    }
}
