<?php

namespace App\Support\Intelligence\RentalUsageAnomaly;

use App\Enums\RentalContractStatus;
use App\Models\RentalContract;
use App\Models\RentalUsageAnomalyResult;
use App\Models\RentalUsageAnomalyRun;

final class FindCanonicalRentalUsageAnomaly
{
    public function forContract(RentalContract $contract): ?RentalUsageAnomalyResult
    {
        if (! in_array($contract->status, [RentalContractStatus::Returned, RentalContractStatus::Closed], true)) {
            return null;
        }

        $finishedAt = RentalUsageAnomalyRun::query()
            ->select('finished_at')
            ->whereColumn(
                'rental_usage_anomaly_runs.id',
                'rental_usage_anomaly_results.rental_usage_anomaly_run_id',
            )
            ->limit(1);

        return $contract->rentalUsageAnomalyResults()
            ->canonicalReviewCandidate()
            ->whereHas('run', fn ($query) => $query->succeededUsable())
            ->with(['run', 'latestReview'])
            ->orderByDesc($finishedAt)
            ->orderByDesc('rental_usage_anomaly_results.id')
            ->first();
    }
}
