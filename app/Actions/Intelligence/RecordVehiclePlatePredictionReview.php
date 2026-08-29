<?php

namespace App\Actions\Intelligence;

use App\Enums\VehiclePlatePredictionStatus;
use App\Enums\VehiclePlateReviewDecision;
use App\Exceptions\VehiclePlatePredictionAlreadyReviewedException;
use App\Models\User;
use App\Models\VehiclePlatePredictionReview;
use App\Models\VehiclePlatePredictionRun;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\VehiclePlate\VehiclePlateHybridContract;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordVehiclePlatePredictionReview
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(
        VehiclePlatePredictionRun $run,
        User $actor,
        VehiclePlateReviewDecision $decision,
        ?string $verifiedCanonical,
        ?string $note,
    ): VehiclePlatePredictionReview {
        $this->assertActor($run, $actor);

        return DB::transaction(function () use (
            $run,
            $actor,
            $decision,
            $verifiedCanonical,
            $note,
        ): VehiclePlatePredictionReview {
            $locked = VehiclePlatePredictionRun::query()
                ->with('review')
                ->lockForUpdate()
                ->findOrFail($run->id);
            if ($locked->status !== VehiclePlatePredictionStatus::Succeeded) {
                throw ValidationException::withMessages([
                    'decision' => 'Seule une analyse terminée peut être revue.',
                ]);
            }
            if ($locked->review !== null) {
                throw new VehiclePlatePredictionAlreadyReviewedException;
            }

            if ($decision === VehiclePlateReviewDecision::Ignored) {
                $verifiedCanonical = null;
            } elseif (! is_string($verifiedCanonical)
                || ! VehiclePlateHybridContract::isCanonical($verifiedCanonical)) {
                throw ValidationException::withMessages([
                    'verified_canonical' => 'Saisissez une plaque marocaine complète au format 12345|أ|7.',
                ]);
            }
            if ($decision === VehiclePlateReviewDecision::Confirmed
                && (! is_string($locked->suggested_canonical)
                    || ! hash_equals($locked->suggested_canonical, $verifiedCanonical ?? ''))) {
                throw ValidationException::withMessages([
                    'decision' => 'Une confirmation doit reprendre exactement la suggestion complète.',
                ]);
            }
            if ($decision === VehiclePlateReviewDecision::Corrected
                && is_string($locked->suggested_canonical)
                && hash_equals($locked->suggested_canonical, $verifiedCanonical ?? '')) {
                throw ValidationException::withMessages([
                    'verified_canonical' => 'La correction doit être différente de la suggestion ; utilisez « Confirmée » sinon.',
                ]);
            }

            $review = VehiclePlatePredictionReview::create([
                'agency_id' => $locked->agency_id,
                'vehicle_plate_prediction_run_id' => $locked->id,
                'reviewed_by' => $actor->id,
                'decision' => $decision,
                'verified_canonical' => $verifiedCanonical,
                'note' => $note,
                'effect' => VehiclePlateHybridContract::OPERATIONAL_EFFECT,
                'reviewed_at' => now(),
            ]);

            // The audit records the feedback event, never the plate value.
            $this->audit->record('prediction.vehicle_plate.human_correction_recorded', $locked, [], [
                'run_id' => $locked->run_id,
                'vehicle_id' => $locked->vehicle_id,
                'decision' => $decision->value,
                'training_feedback_available' => $decision !== VehiclePlateReviewDecision::Ignored,
                'effect' => VehiclePlateHybridContract::OPERATIONAL_EFFECT,
            ]);

            return $review;
        }, 3);
    }

    private function assertActor(VehiclePlatePredictionRun $run, User $actor): void
    {
        $contextAgency = $this->context->agencyId();
        if ($actor->tenant_id !== $run->tenant_id
            || $this->context->tenantId() !== $run->tenant_id
            || ! $actor->is_active
            || ! $actor->hasPermission('prediction.view')
            || ! $actor->hasPermission('prediction.plate.review')
            || ($actor->agency_id !== null && $actor->agency_id !== $run->agency_id)
            || ($contextAgency !== null && $contextAgency !== $run->agency_id)) {
            throw new AuthorizationException;
        }
    }
}
