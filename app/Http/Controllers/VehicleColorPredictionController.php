<?php

namespace App\Http\Controllers;

use App\Actions\Intelligence\QueueVehicleColorPrediction;
use App\Actions\Intelligence\RecordVehicleColorPredictionReview;
use App\Enums\VehicleColorReviewDecision;
use App\Http\Requests\ReviewVehicleColorPredictionRequest;
use App\Http\Requests\StoreVehicleColorPredictionRequest;
use App\Models\Vehicle;
use App\Models\VehicleColorPredictionRun;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\VehicleColor\VehicleColorContract;
use App\Support\Intelligence\VehicleColor\VehicleColorInputArtifact;
use App\Support\Intelligence\VehicleColor\VehicleColorModelArtifact;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VehicleColorPredictionController extends Controller
{
    private const VEHICLE_SELECTOR_LIMIT = 50;

    public function index(
        Request $request,
        TenantContext $context,
        VehicleColorModelArtifact $modelArtifact,
    ): View {
        $this->authorize('viewAny', VehicleColorPredictionRun::class);

        $validated = $request->validate([
            'vehicle_search' => ['nullable', 'string', 'max:100'],
        ]);
        $vehicleSearch = trim((string) ($validated['vehicle_search'] ?? ''));
        $vehicles = Vehicle::query()
            ->with('agency')
            ->when($context->agencyId(), fn ($query, $agencyId) => $query->where('agency_id', $agencyId))
            ->when($vehicleSearch !== '', function ($query) use ($vehicleSearch): void {
                $term = '%'.$vehicleSearch.'%';
                $query->where(fn ($search) => $search
                    ->where('registration_number', 'ilike', $term)
                    ->orWhere('brand', 'ilike', $term)
                    ->orWhere('model', 'ilike', $term));
            })
            ->orderBy('registration_number')
            ->limit(self::VEHICLE_SELECTOR_LIMIT)
            ->get(['id', 'tenant_id', 'agency_id', 'registration_number', 'brand', 'model', 'color']);
        $runs = VehicleColorPredictionRun::query()
            ->with(['vehicle.agency', 'review.reviewer'])
            ->when($context->agencyId(), fn ($query, $agencyId) => $query->where('agency_id', $agencyId))
            ->latest('requested_at')
            ->latest('id')
            ->paginate(20);

        $provider = (string) config('intelligence.vehicle_color_v8.execution_provider');
        $artifactReady = $modelArtifact->configuredIsValid();
        $sanitizer = (string) config('intelligence.vehicle_color_v8.image_sanitizer_script');
        $runtimeReady = (bool) config('intelligence.vehicle_color_v8.enabled')
            && $artifactReady
            && (string) config('intelligence.vehicle_color_v8.python_binary') !== ''
            && is_file((string) config('intelligence.vehicle_color_v8.runtime_script'))
            && is_file($sanitizer)
            && in_array($provider, ['CPUExecutionProvider', 'CUDAExecutionProvider'], true)
            && (int) config('intelligence.vehicle_color_v8.runtime_timeout_seconds') >= 1
            && (int) config('intelligence.vehicle_color_v8.runtime_timeout_seconds') <= 30
            && (int) config('intelligence.vehicle_color_v8.image_sanitizer_timeout_seconds') >= 1
            && (int) config('intelligence.vehicle_color_v8.image_sanitizer_timeout_seconds') <= 15
            && (int) config('intelligence.vehicle_color_v8.max_stored_image_dimension') >= 256
            && (int) config('intelligence.vehicle_color_v8.max_stored_image_dimension') <= 4_096;

        return view('intelligence.vehicle-colors.index', [
            'vehicles' => $vehicles,
            'vehicleSearch' => $vehicleSearch,
            'vehicleSelectorLimit' => self::VEHICLE_SELECTOR_LIMIT,
            'runs' => $runs,
            'runtime' => [
                'enabled' => (bool) config('intelligence.vehicle_color_v8.enabled'),
                'artifact_ready' => $artifactReady,
                'ready' => $runtimeReady,
                'provider' => $provider,
            ],
            'canRun' => $runtimeReady && auth()->user()->hasPermission('prediction.color.review'),
            'canReview' => auth()->user()->hasPermission('prediction.color.review'),
            'contract' => [
                'model_name' => VehicleColorContract::MODEL_NAME,
                'model_version' => VehicleColorContract::MODEL_VERSION,
                'threshold' => VehicleColorContract::ACCEPTED_THRESHOLD,
                'macro_f1' => 0.914989,
                'balanced_accuracy' => 0.90625,
                'minimum_recall' => 0.8,
                'ece' => 0.03346,
                'accepted_precision' => 1.0,
                'coverage' => 0.59375,
                'reject_false_acceptance' => 0.05,
            ],
        ]);
    }

    public function store(
        StoreVehicleColorPredictionRequest $request,
        QueueVehicleColorPrediction $queue,
        TenantContext $context,
    ): RedirectResponse {
        $vehicle = Vehicle::query()
            ->when($context->agencyId(), fn ($query, $agencyId) => $query->where('agency_id', $agencyId))
            ->findOrFail((int) $request->validated('vehicle_id'));
        $image = $request->file('image');
        abort_unless($image instanceof UploadedFile, 422);

        $run = $queue->handle($vehicle, $image, $request->user());

        return redirect()->route('intelligence.vehicle-colors.index')->with(
            'status',
            'Analyse couleur '.$run->run_id.' ajoutée à la queue Intelligence.',
        );
    }

    public function input(
        VehicleColorPredictionRun $colorPrediction,
        VehicleColorInputArtifact $artifact,
        AuditRecorder $audit,
    ): StreamedResponse {
        $this->authorize('view', $colorPrediction);
        abort_unless($artifact->valid($colorPrediction), 404);

        $audit->record('prediction.vehicle_color.input_viewed', $colorPrediction, [], [
            'run_id' => $colorPrediction->run_id,
            'vehicle_id' => $colorPrediction->vehicle_id,
            'input_mime' => $colorPrediction->input_mime,
            'effect' => VehicleColorContract::OPERATIONAL_EFFECT,
        ]);

        $disk = Storage::disk((string) config('intelligence.vehicle_color_v8.disk'));
        $path = $colorPrediction->input_stored_path;
        $input = $disk->readStream($path);
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
            'Content-Type' => $colorPrediction->input_mime,
            'Content-Length' => (string) $colorPrediction->input_bytes,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function review(
        ReviewVehicleColorPredictionRequest $request,
        VehicleColorPredictionRun $colorPrediction,
        RecordVehicleColorPredictionReview $record,
    ): RedirectResponse {
        $data = $request->validated();
        $note = isset($data['note']) && trim((string) $data['note']) !== ''
            ? trim((string) $data['note'])
            : null;
        $record->handle(
            $colorPrediction,
            $request->user(),
            VehicleColorReviewDecision::from($data['decision']),
            $note,
        );

        return redirect()->route('intelligence.vehicle-colors.index')->with(
            'status',
            'Décision humaine enregistrée sans modifier la fiche véhicule.',
        );
    }
}
