<?php

namespace App\Http\Controllers;

use App\Actions\Intelligence\QueueVehicleColorPrediction;
use App\Enums\VehicleColorPredictionStatus;
use App\Exceptions\VehicleColorRuntimeUnavailableException;
use App\Http\Requests\StoreVehicleColorPreparationRequest;
use App\Models\VehicleColorPredictionRun;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class VehicleColorAssistantController extends Controller
{
    public function store(
        StoreVehicleColorPreparationRequest $request,
        QueueVehicleColorPrediction $queue,
    ): JsonResponse {
        $image = $request->file('image');
        abort_unless($image instanceof UploadedFile, 422);

        try {
            $run = $queue->handlePreparation(
                (int) $request->validated('agency_id'),
                $image,
                $request->user(),
            );
        } catch (AuthorizationException|ValidationException $exception) {
            throw $exception;
        } catch (VehicleColorRuntimeUnavailableException) {
            return $this->unavailable();
        } catch (Throwable $exception) {
            try {
                report(new RuntimeException(
                    'VEHICLE_COLOR_ASSISTANT_QUEUE_FAILED ['.$exception::class.']',
                ));
            } catch (Throwable) {
                // Le signalement ne doit jamais exposer ou remplacer l’erreur assainie.
            }

            return $this->unavailable();
        }

        return response()->json([
            'run_id' => $run->run_id,
            'status' => $run->status->value,
            'status_url' => route('vehicles.color-assistant.show', $run),
        ], 202);
    }

    public function show(VehicleColorPredictionRun $colorPrediction): JsonResponse
    {
        $this->authorize('viewForVehicleCreation', $colorPrediction);
        $run = $this->expireStaleRun($colorPrediction);

        return response()->json([
            'status' => $run->status->value,
            'suggested_color' => $run->hasDisplayableCandidate() ? [
                'value' => $run->suggested_color,
                'label' => $run->outcomeLabel(),
            ] : null,
            'confidence' => $run->hasDisplayableCandidate()
                ? round((float) $run->confidence, 7)
                : null,
            'message' => $this->message($run),
        ]);
    }

    private function expireStaleRun(
        VehicleColorPredictionRun $run,
    ): VehicleColorPredictionRun {
        if (! in_array($run->status, [
            VehicleColorPredictionStatus::Queued,
            VehicleColorPredictionStatus::Running,
        ], true)) {
            return $run;
        }

        $staleAfter = (int) config('intelligence.vehicle_color_v8.runtime_stale_after_seconds');
        $reference = $run->started_at ?? $run->requested_at;
        if ($staleAfter < 60 || $reference === null || $reference->addSeconds($staleAfter)->isFuture()) {
            return $run;
        }

        DB::table('vehicle_color_prediction_runs')
            ->where('tenant_id', $run->tenant_id)
            ->where('id', $run->id)
            ->whereIn('status', [
                VehicleColorPredictionStatus::Queued->value,
                VehicleColorPredictionStatus::Running->value,
            ])->update([
                'status' => VehicleColorPredictionStatus::Failed->value,
                'failure_code' => 'RUN_STALE_RECOVERED',
                'started_at' => $run->started_at ?? $run->requested_at,
                'finished_at' => now(),
            ]);

        return $run->refresh();
    }

    private function message(VehicleColorPredictionRun $run): string
    {
        return match ($run->status) {
            VehicleColorPredictionStatus::Queued,
            VehicleColorPredictionStatus::Running => 'Analyse de la photo en cours…',
            VehicleColorPredictionStatus::Succeeded => $run->hasLowConfidenceCandidate()
                ? 'Vérification visuelle recommandée.'
                : 'Vous pouvez modifier cette couleur avant l’enregistrement.',
            VehicleColorPredictionStatus::Failed => 'La couleur n’a pas pu être déterminée. Sélectionnez-la manuellement.',
        };
    }

    private function unavailable(): JsonResponse
    {
        return response()->json([
            'message' => 'La couleur n’a pas pu être déterminée. Sélectionnez-la manuellement.',
        ], 503);
    }
}
