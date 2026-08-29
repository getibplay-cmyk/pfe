<?php

namespace App\Http\Controllers;

use App\Actions\Intelligence\QueueVehiclePlatePrediction;
use App\Actions\Intelligence\RecordVehiclePlatePredictionReview;
use App\Enums\VehiclePlateReviewDecision;
use App\Http\Requests\ReviewVehiclePlatePredictionRequest;
use App\Http\Requests\StoreVehiclePlatePredictionRequest;
use App\Models\Vehicle;
use App\Models\VehiclePlatePredictionRun;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\VehiclePlate\VehiclePlateDetectorRuntime;
use App\Support\Intelligence\VehiclePlate\VehiclePlateHybridContract;
use App\Support\Intelligence\VehiclePlate\VehiclePlateHybridRuntime;
use App\Support\Intelligence\VehiclePlate\VehiclePlateInputArtifact;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VehiclePlatePredictionController extends Controller
{
    private const VEHICLE_SELECTOR_LIMIT = 50;

    public function index(
        Request $request,
        TenantContext $context,
        VehiclePlateHybridRuntime $runtime,
        VehiclePlateDetectorRuntime $detectorRuntime,
    ): View {
        $this->authorize('viewAny', VehiclePlatePredictionRun::class);

        $validated = $request->validate([
            'vehicle_search' => ['nullable', 'string', 'max:100'],
        ]);
        $vehicleSearch = trim((string) ($validated['vehicle_search'] ?? ''));
        $vehicles = Vehicle::query()
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
            ->get(['id', 'tenant_id', 'agency_id', 'registration_number', 'brand', 'model']);
        $runs = VehiclePlatePredictionRun::query()
            ->with(['vehicle.agency', 'review.reviewer'])
            ->when($context->agencyId(), fn ($query, $agencyId) => $query->where('agency_id', $agencyId))
            ->latest('requested_at')
            ->latest('id')
            ->paginate(20);

        $sanitizer = (string) config(
            'intelligence.vehicle_plate_hybrid_review.image_sanitizer_script',
        );
        $ocrReady = (bool) config('intelligence.vehicle_plate_hybrid_review.enabled')
            && $runtime->configured()
            && is_file($sanitizer)
            && (int) config('intelligence.vehicle_plate_hybrid_review.image_sanitizer_timeout_seconds') >= 1
            && (int) config('intelligence.vehicle_plate_hybrid_review.image_sanitizer_timeout_seconds') <= 15
            && (int) config('intelligence.vehicle_plate_hybrid_review.max_stored_image_dimension') >= 256
            && (int) config('intelligence.vehicle_plate_hybrid_review.max_stored_image_dimension') <= 4_096;
        $detectorReady = $ocrReady && $detectorRuntime->ready();

        return view('intelligence.vehicle-plates.index', [
            'vehicles' => $vehicles,
            'vehicleSearch' => $vehicleSearch,
            'vehicleSelectorLimit' => self::VEHICLE_SELECTOR_LIMIT,
            'runs' => $runs,
            'runtime' => [
                'enabled' => (bool) config('intelligence.vehicle_plate_hybrid_review.enabled'),
                'ocr_ready' => $ocrReady,
                'detector_ready' => $detectorReady,
                'ocr_device' => (string) config('intelligence.vehicle_plate_hybrid_review.device'),
                'detector_device' => (string) config(
                    'intelligence.vehicle_plate_hybrid_review.detector.device',
                ),
            ],
            'canRun' => $ocrReady && auth()->user()->hasPermission('prediction.plate.review'),
            'canRunFullImage' => $detectorReady
                && auth()->user()->hasPermission('prediction.plate.review'),
            'canReview' => auth()->user()->hasPermission('prediction.plate.review'),
            'pilot' => [
                'total' => 1819,
                'complete' => 821,
                'fallback' => 1656,
                'reviewed' => 36,
                'accuracy_qualified' => false,
            ],
        ]);
    }

    public function store(
        StoreVehiclePlatePredictionRequest $request,
        QueueVehiclePlatePrediction $queue,
        TenantContext $context,
    ): RedirectResponse {
        $vehicle = Vehicle::query()
            ->when($context->agencyId(), fn ($query, $agencyId) => $query->where('agency_id', $agencyId))
            ->findOrFail((int) $request->validated('vehicle_id'));
        $image = $request->file('image');
        abort_unless($image instanceof UploadedFile, 422);

        $run = $queue->handle(
            $vehicle,
            $image,
            $request->user(),
            (string) $request->validated('input_kind'),
        );

        return redirect()->route('intelligence.vehicle-plates.index')->with(
            'status',
            'Analyse ANPR '.$run->run_id.' ajoutée à la queue Intelligence.',
        );
    }

    public function input(
        VehiclePlatePredictionRun $platePrediction,
        VehiclePlateInputArtifact $artifact,
        AuditRecorder $audit,
    ): StreamedResponse {
        $this->authorize('view', $platePrediction);
        abort_unless($artifact->valid($platePrediction), 404);

        $audit->record('prediction.vehicle_plate.input_viewed', $platePrediction, [], [
            'run_id' => $platePrediction->run_id,
            'vehicle_id' => $platePrediction->vehicle_id,
            'input_mime' => $platePrediction->input_mime,
            'effect' => VehiclePlateHybridContract::OPERATIONAL_EFFECT,
        ]);

        $disk = Storage::disk((string) config('intelligence.vehicle_plate_hybrid_review.disk'));
        return $this->streamPrivateArtifact(
            $disk,
            (string) $platePrediction->input_stored_path,
            (string) $platePrediction->input_mime,
            (int) $platePrediction->input_bytes,
        );
    }

    public function crop(
        VehiclePlatePredictionRun $platePrediction,
        VehiclePlateInputArtifact $artifact,
        AuditRecorder $audit,
    ): StreamedResponse {
        $this->authorize('view', $platePrediction);
        abort_unless($artifact->validReviewCrop($platePrediction), 404);

        $audit->record('prediction.vehicle_plate.crop_viewed', $platePrediction, [], [
            'run_id' => $platePrediction->run_id,
            'vehicle_id' => $platePrediction->vehicle_id,
            'detector_executed' => $platePrediction->usesDetector(),
            'effect' => VehiclePlateHybridContract::OPERATIONAL_EFFECT,
        ]);

        $disk = Storage::disk((string) config('intelligence.vehicle_plate_hybrid_review.disk'));

        return $this->streamPrivateArtifact(
            $disk,
            $artifact->reviewCropStoredPath($platePrediction),
            'image/jpeg',
            $artifact->reviewCropBytes($platePrediction),
        );
    }

    public function review(
        ReviewVehiclePlatePredictionRequest $request,
        VehiclePlatePredictionRun $platePrediction,
        RecordVehiclePlatePredictionReview $record,
    ): RedirectResponse {
        $data = $request->validated();
        $note = isset($data['note']) && trim((string) $data['note']) !== ''
            ? trim((string) $data['note'])
            : null;
        $canonical = isset($data['verified_canonical'])
            && trim((string) $data['verified_canonical']) !== ''
                ? trim((string) $data['verified_canonical'])
                : null;
        $record->handle(
            $platePrediction,
            $request->user(),
            VehiclePlateReviewDecision::from($data['decision']),
            $canonical,
            $note,
        );

        return redirect()->route('intelligence.vehicle-plates.index')->with(
            'status',
            'Correction humaine enregistrée sans modifier la fiche véhicule.',
        );
    }

    private function streamPrivateArtifact(
        FilesystemAdapter $disk,
        string $storedPath,
        string $mime,
        int $bytes,
    ): StreamedResponse {
        $input = $disk->readStream($storedPath);
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
            'Content-Type' => $mime,
            'Content-Length' => (string) $bytes,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
