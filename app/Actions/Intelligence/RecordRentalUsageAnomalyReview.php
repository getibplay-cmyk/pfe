<?php

namespace App\Actions\Intelligence;

use App\Enums\RentalUsageAnomalyReviewDecision;
use App\Enums\RentalUsageAnomalyRunStatus;
use App\Models\RentalUsageAnomalyResult;
use App\Models\RentalUsageAnomalyReview;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\RentalUsageAnomaly\RentalUsageAnomalyContract;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordRentalUsageAnomalyReview
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(
        RentalUsageAnomalyResult $result,
        User $actor,
        RentalUsageAnomalyReviewDecision $decision,
        ?string $note,
    ): RentalUsageAnomalyReview {
        $this->assertActor($result, $actor);

        return DB::transaction(function () use ($result, $actor, $decision, $note): RentalUsageAnomalyReview {
            $locked = RentalUsageAnomalyResult::query()
                ->with('run')
                ->lockForUpdate()
                ->findOrFail($result->id);
            if ($locked->run->status !== RentalUsageAnomalyRunStatus::Succeeded
                || $locked->run->data_status !== 'usable'
                || $locked->run->default_budget_basis_points !== RentalUsageAnomalyContract::DEFAULT_BUDGET_BASIS_POINTS
                || $locked->run->primary_model !== RentalUsageAnomalyContract::PRIMARY_MODEL
                || $locked->run->primary_version !== RentalUsageAnomalyContract::PRIMARY_VERSION
                || $locked->run->operational_effect !== RentalUsageAnomalyContract::OPERATIONAL_EFFECT
                || ! $locked->primary_selected_010
                || $locked->operational_effect !== RentalUsageAnomalyContract::OPERATIONAL_EFFECT) {
                throw ValidationException::withMessages([
                    'decision' => 'Seul un cas consultatif canonique terminé peut être revu.',
                ]);
            }
            $review = RentalUsageAnomalyReview::create([
                'agency_id' => $locked->agency_id,
                'rental_usage_anomaly_result_id' => $locked->id,
                'reviewed_by' => $actor->id,
                'decision' => $decision,
                'note' => $note,
                'effect' => RentalUsageAnomalyContract::OPERATIONAL_EFFECT,
                'reviewed_at' => now(),
            ]);

            $this->audit->record('prediction.rental_usage_anomaly.human_review_recorded', $locked, [], [
                'run_id' => $locked->run->run_id,
                'primary_rank' => $locked->primary_rank,
                'decision' => $decision->value,
                'effect' => RentalUsageAnomalyContract::OPERATIONAL_EFFECT,
            ]);

            return $review;
        }, 3);
    }

    private function assertActor(RentalUsageAnomalyResult $result, User $actor): void
    {
        $contextAgency = $this->context->agencyId();
        if ($actor->tenant_id !== $result->tenant_id
            || $this->context->tenantId() !== $result->tenant_id
            || ! $actor->is_active
            || ! $actor->hasPermission('prediction.view')
            || ! $actor->hasPermission('prediction.anomaly.review')
            || ($actor->agency_id !== null && $actor->agency_id !== $result->agency_id)
            || ($contextAgency !== null && $contextAgency !== $result->agency_id)) {
            throw new AuthorizationException;
        }
    }
}
