<?php

namespace App\Actions\Intelligence;

use App\Enums\VehicleColorPredictionStatus;
use App\Enums\VehicleColorReviewDecision;
use App\Exceptions\VehicleColorPredictionAlreadyReviewedException;
use App\Models\User;
use App\Models\VehicleColorPredictionReview;
use App\Models\VehicleColorPredictionRun;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\VehicleColor\VehicleColorContract;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordVehicleColorPredictionReview
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(
        VehicleColorPredictionRun $run,
        User $actor,
        VehicleColorReviewDecision $decision,
        ?string $note,
    ): VehicleColorPredictionReview {
        $this->assertActor($run, $actor);

        return DB::transaction(function () use ($run, $actor, $decision, $note): VehicleColorPredictionReview {
            $locked = VehicleColorPredictionRun::query()
                ->with('review')
                ->lockForUpdate()
                ->findOrFail($run->id);
            if ($locked->status !== VehicleColorPredictionStatus::Succeeded) {
                throw ValidationException::withMessages([
                    'decision' => 'Seule une analyse terminée peut être revue.',
                ]);
            }
            if ($locked->review !== null) {
                throw new VehicleColorPredictionAlreadyReviewedException;
            }
            if ($decision === VehicleColorReviewDecision::Accepted && $locked->model_accepted !== true) {
                throw ValidationException::withMessages([
                    'decision' => 'Une abstention du modèle ne peut pas être acceptée.',
                ]);
            }

            $review = VehicleColorPredictionReview::create([
                'agency_id' => $locked->agency_id,
                'vehicle_color_prediction_run_id' => $locked->id,
                'reviewed_by' => $actor->id,
                'decision' => $decision,
                'note' => $note,
                'effect' => VehicleColorContract::OPERATIONAL_EFFECT,
                'reviewed_at' => now(),
            ]);

            $this->audit->record('prediction.vehicle_color.human_decision_recorded', $locked, [], [
                'run_id' => $locked->run_id,
                'vehicle_id' => $locked->vehicle_id,
                'decision' => $decision->value,
                'effect' => VehicleColorContract::OPERATIONAL_EFFECT,
            ]);

            return $review;
        }, 3);
    }

    private function assertActor(VehicleColorPredictionRun $run, User $actor): void
    {
        $contextAgency = $this->context->agencyId();
        if ($actor->tenant_id !== $run->tenant_id
            || $this->context->tenantId() !== $run->tenant_id
            || ! $actor->is_active
            || ! $actor->hasPermission('prediction.color.review')
            || ($actor->agency_id !== null && $actor->agency_id !== $run->agency_id)
            || ($contextAgency !== null && $contextAgency !== $run->agency_id)) {
            throw new AuthorizationException;
        }
    }
}
