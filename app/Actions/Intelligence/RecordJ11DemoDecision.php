<?php

namespace App\Actions\Intelligence;

use App\Enums\J11DemoDecision;
use App\Models\AiAdvisoryRecordDemo;
use App\Models\AiHumanDecisionDemo;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\J11\J11ContractDemoGate;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class RecordJ11DemoDecision
{
    public function __construct(
        private readonly J11ContractDemoGate $gate,
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(
        AiAdvisoryRecordDemo $record,
        User $actor,
        J11DemoDecision $decision,
        string $reasonCode,
    ): AiHumanDecisionDemo {
        $this->gate->assertEnabled();
        $this->assertActor($record, $actor);

        return DB::transaction(function () use ($record, $actor, $decision, $reasonCode): AiHumanDecisionDemo {
            $locked = AiAdvisoryRecordDemo::query()->lockForUpdate()->findOrFail($record->id);

            $humanDecision = AiHumanDecisionDemo::create([
                'agency_id' => $locked->agency_id,
                'ai_advisory_record_demo_id' => $locked->id,
                'actor_user_id' => $actor->id,
                'decision' => $decision,
                'reason_code' => $reasonCode,
                'note' => null,
                'effect' => 'NO_OPERATIONAL_ACTION',
                'created_at' => now(),
            ]);

            $this->audit->record('prediction.demo.human_decision_recorded', $locked, [], [
                'module_id' => $locked->module_id->value,
                'decision' => $decision->value,
                'reason_code' => $reasonCode,
                'effect' => 'NO_OPERATIONAL_ACTION',
            ]);

            return $humanDecision;
        }, 3);
    }

    private function assertActor(AiAdvisoryRecordDemo $record, User $actor): void
    {
        if ($actor->tenant_id !== $record->tenant_id
            || ! $actor->hasPermission('prediction.demo.review')
            || ($actor->agency_id !== null && $actor->agency_id !== $record->agency_id)
            || $this->context->tenantId() !== $record->tenant_id) {
            throw new AuthorizationException;
        }
    }
}
