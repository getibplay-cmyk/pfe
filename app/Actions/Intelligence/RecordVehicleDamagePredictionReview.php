<?php

namespace App\Actions\Intelligence;

use App\Enums\VehicleDamagePredictionStatus;
use App\Enums\VehicleDamageReviewDecision;
use App\Exceptions\VehicleDamagePredictionAlreadyReviewedException;
use App\Models\User;
use App\Models\VehicleDamagePredictionReview;
use App\Models\VehicleDamagePredictionRun;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\VehicleDamage\VehicleDamageContract;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordVehicleDamagePredictionReview
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(
        VehicleDamagePredictionRun $run,
        User $actor,
        VehicleDamageReviewDecision $decision,
        ?string $note,
    ): VehicleDamagePredictionReview {
        $this->assertActor($run, $actor);

        return DB::transaction(function () use ($run, $actor, $decision, $note): VehicleDamagePredictionReview {
            $locked = VehicleDamagePredictionRun::query()
                ->with('review')
                ->lockForUpdate()
                ->findOrFail($run->id);
            if ($locked->status !== VehicleDamagePredictionStatus::Succeeded) {
                throw ValidationException::withMessages([
                    'decision' => 'Seule une analyse terminée peut être revue.',
                ]);
            }
            if ($locked->review !== null) {
                throw new VehicleDamagePredictionAlreadyReviewedException;
            }
            if ($decision === VehicleDamageReviewDecision::Confirmed
                && ($locked->quality_status !== 'usable' || $locked->suggested_damage !== true)) {
                throw ValidationException::withMessages([
                    'decision' => 'Seule une zone candidate exploitable peut être confirmée.',
                ]);
            }

            $review = VehicleDamagePredictionReview::create([
                'agency_id' => $locked->agency_id,
                'vehicle_damage_prediction_run_id' => $locked->id,
                'reviewed_by' => $actor->id,
                'decision' => $decision,
                'note' => $note,
                'effect' => VehicleDamageContract::OPERATIONAL_EFFECT,
                'reviewed_at' => now(),
            ]);

            $this->audit->record('prediction.vehicle_damage.human_decision_recorded', $locked, [], [
                'run_id' => $locked->run_id,
                'vehicle_id' => $locked->vehicle_id,
                'vehicle_inspection_id' => $locked->vehicle_inspection_id,
                'decision' => $decision->value,
                'effect' => VehicleDamageContract::OPERATIONAL_EFFECT,
            ]);

            return $review;
        }, 3);
    }

    private function assertActor(VehicleDamagePredictionRun $run, User $actor): void
    {
        $contextAgency = $this->context->agencyId();
        if ($actor->tenant_id !== $run->tenant_id
            || $this->context->tenantId() !== $run->tenant_id
            || ! $actor->is_active
            || ! $actor->hasPermission('prediction.view')
            || ! $actor->hasPermission('prediction.damage.review')
            || ($actor->agency_id !== null && $actor->agency_id !== $run->agency_id)
            || ($contextAgency !== null && $contextAgency !== $run->agency_id)) {
            throw new AuthorizationException;
        }
    }
}
