<?php

namespace App\Http\Controllers;

use App\Actions\Intelligence\QueueVehiclePlatePrediction;
use App\Enums\VehiclePlatePredictionStatus;
use App\Exceptions\VehiclePlateRuntimeUnavailableException;
use App\Http\Requests\StoreVehicleRegistrationPreparationRequest;
use App\Models\VehiclePlatePredictionRun;
use App\Support\Intelligence\VehiclePlate\VehiclePlateDetectorContract;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class VehicleRegistrationAssistantController extends Controller
{
    public function store(
        StoreVehicleRegistrationPreparationRequest $request,
        QueueVehiclePlatePrediction $queue,
    ): JsonResponse {
        $image = $request->file('image');
        abort_unless($image instanceof UploadedFile, 422);

        try {
            $run = $queue->handlePreparation(
                (int) $request->validated('agency_id'),
                $image,
                $request->user(),
                (string) $request->validated('input_kind'),
            );
        } catch (AuthorizationException|ValidationException $exception) {
            throw $exception;
        } catch (VehiclePlateRuntimeUnavailableException) {
            return $this->unavailable();
        } catch (Throwable $exception) {
            try {
                report(new RuntimeException(
                    'VEHICLE_REGISTRATION_ASSISTANT_QUEUE_FAILED ['.$exception::class.']',
                ));
            } catch (Throwable) {
                // Le signalement ne remplace jamais la réponse client assainie.
            }

            return $this->unavailable();
        }

        return response()->json([
            'run_id' => $run->run_id,
            'status' => $run->status->value,
            'status_url' => route('vehicles.registration-assistant.show', $run),
        ], 202);
    }

    public function show(VehiclePlatePredictionRun $platePrediction): JsonResponse
    {
        $this->authorize('viewForVehicleCreation', $platePrediction);
        $run = $this->expireStaleRun($platePrediction);
        $displayable = $run->hasCompleteSuggestion();

        return response()->json([
            'status' => $run->status->value,
            'suggestion' => $displayable ? [
                'value' => $run->suggested_canonical,
                'label' => $run->display_text,
            ] : null,
            'confidence' => $displayable ? round((float) $run->confidence, 7) : null,
            'displayable' => $displayable,
            'requires_close_up' => $this->requiresCloseUp($run),
            'message' => $this->message($run, $displayable),
        ]);
    }

    private function expireStaleRun(VehiclePlatePredictionRun $run): VehiclePlatePredictionRun
    {
        if (! in_array($run->status, [
            VehiclePlatePredictionStatus::Queued,
            VehiclePlatePredictionStatus::Running,
        ], true)) {
            return $run;
        }

        $staleAfter = (int) config(
            'intelligence.vehicle_plate_hybrid_review.runtime_stale_after_seconds',
        );
        $reference = $run->started_at ?? $run->requested_at;
        if ($staleAfter < 60 || $reference === null || $reference->addSeconds($staleAfter)->isFuture()) {
            return $run;
        }

        DB::table('vehicle_plate_prediction_runs')
            ->where('tenant_id', $run->tenant_id)
            ->where('id', $run->id)
            ->whereIn('status', [
                VehiclePlatePredictionStatus::Queued->value,
                VehiclePlatePredictionStatus::Running->value,
            ])->update([
                'status' => VehiclePlatePredictionStatus::Failed->value,
                'failure_code' => 'RUN_STALE_RECOVERED',
                'started_at' => $run->started_at ?? $run->requested_at,
                'finished_at' => now(),
            ]);

        return $run->refresh();
    }

    private function requiresCloseUp(VehiclePlatePredictionRun $run): bool
    {
        if ($run->hasCompleteSuggestion()
            || in_array($run->status, [
                VehiclePlatePredictionStatus::Queued,
                VehiclePlatePredictionStatus::Running,
            ], true)) {
            return false;
        }

        return $run->input_kind === VehiclePlateDetectorContract::FULL_IMAGE
            || in_array($run->suggestion_status, [
                'partial_segmented_suggestion',
                'empty_suggestion',
            ], true);
    }

    private function message(VehiclePlatePredictionRun $run, bool $displayable): string
    {
        if (in_array($run->status, [
            VehiclePlatePredictionStatus::Queued,
            VehiclePlatePredictionStatus::Running,
        ], true)) {
            return 'Lecture de la photo en cours…';
        }
        if ($displayable) {
            return 'Vérifiez l’immatriculation avant d’enregistrer le véhicule.';
        }
        if ($run->suggestion_status === 'partial_segmented_suggestion') {
            return 'Lecture incomplète. Vérifiez manuellement ou essayez une photo rapprochée.';
        }
        if ($this->requiresCloseUp($run)) {
            return 'Plaque non détectée. Ajoutez une photo rapprochée de la plaque.';
        }

        return 'L’immatriculation n’a pas pu être lue. Saisissez-la manuellement.';
    }

    private function unavailable(): JsonResponse
    {
        return response()->json([
            'message' => 'L’immatriculation n’a pas pu être lue. Saisissez-la manuellement.',
        ], 503);
    }
}
