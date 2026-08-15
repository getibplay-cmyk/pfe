<?php

namespace App\Actions\Intelligence;

use App\Enums\IntelligenceResultBatchDecision as Decision;
use App\Exceptions\FleetReallocationAlreadyReviewedException;
use App\Models\FleetReallocationDecision;
use App\Models\FleetReallocationProposal;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\FleetReallocation\FleetReallocationContract;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class RecordFleetReallocationDecision
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(
        FleetReallocationProposal $proposal,
        User $actor,
        Decision $decision,
        string $reasonCode,
    ): FleetReallocationDecision {
        $this->assertActor($proposal, $actor);

        return DB::transaction(function () use ($proposal, $actor, $decision, $reasonCode): FleetReallocationDecision {
            $locked = FleetReallocationProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            if ($locked->decision()->exists()) {
                throw new FleetReallocationAlreadyReviewedException;
            }

            $recorded = FleetReallocationDecision::create([
                'fleet_reallocation_proposal_id' => $locked->id,
                'actor_user_id' => $actor->id,
                'decision' => $decision,
                'reason_code' => $reasonCode,
                'effect' => FleetReallocationContract::OPERATIONAL_EFFECT,
                'created_at' => now(),
            ]);

            $this->audit->record('prediction.fleet_reallocation.human_decision_recorded', $locked, [], [
                'proposal_id' => $locked->proposal_id,
                'decision' => $decision->value,
                'reason_code' => $reasonCode,
                'effect' => FleetReallocationContract::OPERATIONAL_EFFECT,
            ]);

            return $recorded;
        }, 3);
    }

    private function assertActor(FleetReallocationProposal $proposal, User $actor): void
    {
        if ($actor->tenant_id !== $proposal->tenant_id
            || $this->context->tenantId() !== $proposal->tenant_id
            || $actor->agency_id !== null
            || $this->context->agencyId() !== null
            || ! $actor->is_active
            || ! $actor->hasPermission('prediction.demo.review')) {
            throw new AuthorizationException;
        }
    }
}
