<?php

namespace App\Actions\Intelligence;

use App\Enums\IntelligenceResultBatchDecision as Decision;
use App\Exceptions\J14ResultBatchAlreadyReviewedException;
use App\Models\IntelligenceResultBatch;
use App\Models\IntelligenceResultBatchDecision;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class RecordIntelligenceResultBatchDecision
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(
        IntelligenceResultBatch $batch,
        User $actor,
        Decision $decision,
        string $reasonCode,
    ): IntelligenceResultBatchDecision {
        $this->assertActor($batch, $actor);

        return DB::transaction(function () use ($batch, $actor, $decision, $reasonCode): IntelligenceResultBatchDecision {
            $locked = IntelligenceResultBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($locked->decision()->exists()) {
                throw new J14ResultBatchAlreadyReviewedException;
            }

            $recorded = IntelligenceResultBatchDecision::create([
                'agency_id' => $locked->agency_id,
                'intelligence_result_batch_id' => $locked->id,
                'actor_user_id' => $actor->id,
                'decision' => $decision,
                'reason_code' => $reasonCode,
                'effect' => 'NO_OPERATIONAL_ACTION',
                'created_at' => now(),
            ]);

            $this->audit->record('prediction.result_batch.human_decision_recorded', $locked, [], [
                'batch_id' => $locked->batch_id,
                'decision' => $decision->value,
                'reason_code' => $reasonCode,
                'effect' => 'NO_OPERATIONAL_ACTION',
            ]);

            return $recorded;
        }, 3);
    }

    private function assertActor(IntelligenceResultBatch $batch, User $actor): void
    {
        if ($actor->tenant_id !== $batch->tenant_id
            || $this->context->tenantId() !== $batch->tenant_id
            || ! $actor->is_active
            || ! $actor->hasPermission('prediction.demo.review')
            || ($actor->agency_id !== null && $actor->agency_id !== $batch->agency_id)) {
            throw new AuthorizationException;
        }
    }
}
