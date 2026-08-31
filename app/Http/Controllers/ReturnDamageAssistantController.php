<?php

namespace App\Http\Controllers;

use App\Actions\Intelligence\QueueVehicleDamagePrediction;
use App\Enums\RentalContractStatus;
use App\Enums\VehicleDamagePredictionStatus;
use App\Exceptions\VehicleDamageRuntimeUnavailableException;
use App\Http\Requests\StoreReturnDamagePreparationRequest;
use App\Models\RentalContract;
use App\Models\VehicleDamagePredictionRun;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\IntelligencePrivateStorage;
use App\Support\Intelligence\VehicleDamage\VehicleDamageContract;
use App\Support\Intelligence\VehicleDamage\VehicleDamageInputArtifact;
use App\Support\Intelligence\VehicleDamage\VehicleDamageSuggestionPresenter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use UnexpectedValueException;

class ReturnDamageAssistantController extends Controller
{
    private const HUMAN_NOTICE = 'Cette analyse est une aide visuelle. Vérifiez toujours l’ensemble du véhicule avant de valider le retour.';

    public function store(
        StoreReturnDamagePreparationRequest $request,
        RentalContract $contract,
        QueueVehicleDamagePrediction $queue,
    ): JsonResponse {
        $image = $request->file('image');
        abort_unless($image instanceof UploadedFile, 422);

        try {
            $run = $queue->handlePreparation($contract, $image, $request->user());
        } catch (AuthorizationException|ValidationException $exception) {
            throw $exception;
        } catch (VehicleDamageRuntimeUnavailableException) {
            return $this->unavailable();
        } catch (Throwable $exception) {
            try {
                report(new RuntimeException(
                    'RETURN_DAMAGE_ASSISTANT_QUEUE_FAILED ['.$exception::class.']',
                ));
            } catch (Throwable) {
                // La réponse utilisateur reste assainie même si le signalement échoue.
            }

            return $this->unavailable();
        }

        return response()->json([
            'run_id' => $run->run_id,
            'status' => $run->status->value,
            'status_url' => route('contracts.return-damage-assistant.show', [
                $contract,
                'damagePrediction' => $run,
            ]),
        ], 202);
    }

    public function show(
        Request $request,
        RentalContract $contract,
        VehicleDamagePredictionRun $damagePrediction,
        VehicleDamageSuggestionPresenter $presenter,
        VehicleDamageInputArtifact $inputArtifact,
    ): JsonResponse {
        $this->assertAccess($request, $contract, $damagePrediction);
        $run = $this->expireStaleRun($damagePrediction);

        if (in_array($run->status, [
            VehicleDamagePredictionStatus::Queued,
            VehicleDamagePredictionStatus::Running,
        ], true)) {
            return $this->status($run, [], 'Analyse de la photo en cours…');
        }
        if ($run->status === VehicleDamagePredictionStatus::Failed) {
            return $this->status(
                $run,
                [],
                'La photo n’a pas pu être analysée. Poursuivez l’inspection manuelle.',
            );
        }
        if (! $inputArtifact->valid($run)) {
            return response()->json([
                'message' => 'La suggestion n’est pas disponible. Poursuivez l’inspection manuelle.',
            ], 422);
        }

        try {
            $detections = $presenter->detections($run);
        } catch (UnexpectedValueException) {
            return response()->json([
                'message' => 'La suggestion n’est pas disponible. Poursuivez l’inspection manuelle.',
            ], 422);
        }

        $message = match (true) {
            $run->quality_status === 'abstained' => 'La qualité de cette photo ne permet pas de suggestion. Prenez une nouvelle photo et poursuivez l’inspection visuelle.',
            $detections === [] => 'Aucun dommage n’a été suggéré sur cette photo. Poursuivez l’inspection visuelle du véhicule.',
            default => count($detections).' zone(s) de dommage possible à vérifier visuellement.',
        };

        return $this->status($run, $detections, $message, route(
            'contracts.return-damage-assistant.preview',
            [$contract, 'damagePrediction' => $run],
        ));
    }

    public function preview(
        Request $request,
        RentalContract $contract,
        VehicleDamagePredictionRun $damagePrediction,
        VehicleDamageInputArtifact $artifact,
        AuditRecorder $audit,
    ): StreamedResponse {
        $this->assertAccess($request, $contract, $damagePrediction);
        abort_unless($artifact->valid($damagePrediction), 404);

        $audit->record('prediction.vehicle_damage.return_preview_viewed', $damagePrediction, [], [
            'run_id' => $damagePrediction->run_id,
            'rental_contract_id' => $damagePrediction->rental_contract_id,
            'vehicle_id' => $damagePrediction->vehicle_id,
            'effect' => VehicleDamageContract::OPERATIONAL_EFFECT,
        ]);

        $input = IntelligencePrivateStorage::readStream(
            'intelligence.vehicle_damage_v1.disk',
            (string) $damagePrediction->input_stored_path,
        );
        abort_unless(is_resource($input), 404);

        return response()->stream(static function () use ($input): void {
            try {
                while (! feof($input)) {
                    echo fread($input, 8192);
                }
            } finally {
                fclose($input);
            }
        }, 200, [
            'Content-Type' => $damagePrediction->input_mime,
            'Content-Length' => (string) $damagePrediction->input_bytes,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; frame-ancestors 'self'",
        ]);
    }

    private function assertAccess(
        Request $request,
        RentalContract $contract,
        VehicleDamagePredictionRun $run,
    ): void {
        $this->authorize('return', $contract);
        abort_unless(
            $contract->status === RentalContractStatus::Active
                && $request->user()?->hasPermission('inspection.manage')
                && $request->user()?->hasPermission('prediction.view')
                && $request->user()?->hasPermission('prediction.damage.review')
                && $run->tenant_id === $contract->tenant_id
                && $run->agency_id === $contract->agency_id
                && $run->rental_contract_id === $contract->id
                && $run->vehicle_id === $contract->vehicle_id
                && $run->vehicle_inspection_id === null
                && $run->requested_by === $request->user()?->id,
            404,
        );
    }

    /** @param list<array<string, mixed>> $detections */
    private function status(
        VehicleDamagePredictionRun $run,
        array $detections,
        string $message,
        ?string $previewUrl = null,
    ): JsonResponse {
        return response()->json([
            'status' => $run->status->value,
            'detections' => $detections,
            'message' => $message,
            'notice' => self::HUMAN_NOTICE,
            'preview_url' => $previewUrl,
        ]);
    }

    private function expireStaleRun(
        VehicleDamagePredictionRun $run,
    ): VehicleDamagePredictionRun {
        if (! in_array($run->status, [
            VehicleDamagePredictionStatus::Queued,
            VehicleDamagePredictionStatus::Running,
        ], true)) {
            return $run;
        }

        $staleAfter = (int) config('intelligence.vehicle_damage_v1.runtime_stale_after_seconds');
        $reference = $run->started_at ?? $run->requested_at;
        if ($staleAfter < 120
            || $reference === null
            || $reference->addSeconds($staleAfter)->isFuture()) {
            return $run;
        }

        DB::table('vehicle_damage_prediction_runs')
            ->where('tenant_id', $run->tenant_id)
            ->where('id', $run->id)
            ->whereIn('status', [
                VehicleDamagePredictionStatus::Queued->value,
                VehicleDamagePredictionStatus::Running->value,
            ])->update([
                'status' => VehicleDamagePredictionStatus::Failed->value,
                'failure_code' => 'RUN_STALE_RECOVERED',
                'started_at' => $run->started_at ?? $run->requested_at,
                'finished_at' => now(),
            ]);

        return $run->refresh();
    }

    private function unavailable(): JsonResponse
    {
        return response()->json([
            'message' => 'La photo n’a pas pu être analysée. Poursuivez l’inspection manuelle.',
        ], 503);
    }
}
