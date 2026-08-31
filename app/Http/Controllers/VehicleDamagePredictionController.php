<?php

namespace App\Http\Controllers;

use App\Actions\Intelligence\QueueVehicleDamagePrediction;
use App\Actions\Intelligence\RecordVehicleDamagePredictionReview;
use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Enums\IntelligenceCapability;
use App\Enums\VehicleDamageReviewDecision;
use App\Http\Requests\ReviewVehicleDamagePredictionRequest;
use App\Http\Requests\StoreVehicleDamagePredictionRequest;
use App\Models\VehicleDamagePredictionRun;
use App\Models\VehicleInspection;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\IntelligencePrivateStorage;
use App\Support\Intelligence\TenantIntelligenceAccess;
use App\Support\Intelligence\VehicleDamage\VehicleDamageContract;
use App\Support\Intelligence\VehicleDamage\VehicleDamageInputArtifact;
use App\Support\Intelligence\VehicleDamage\VehicleDamageModelArtifact;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VehicleDamagePredictionController extends Controller
{
    private const INSPECTION_SELECTOR_LIMIT = 50;

    public function index(
        Request $request,
        TenantContext $context,
        VehicleDamageModelArtifact $modelArtifact,
        TenantIntelligenceAccess $intelligenceAccess,
    ): View {
        $this->authorize('viewAny', VehicleDamagePredictionRun::class);

        $validated = $request->validate([
            'inspection_search' => ['nullable', 'string', 'max:100'],
        ]);
        $inspectionSearch = trim((string) ($validated['inspection_search'] ?? ''));
        $inspections = VehicleInspection::query()
            ->with(['vehicle.agency', 'rentalContract'])
            ->where('inspection_type', InspectionType::Return->value)
            ->where('status', InspectionStatus::Completed->value)
            ->when($context->agencyId(), fn ($query, $agencyId) => $query->where('agency_id', $agencyId))
            ->when($inspectionSearch !== '', function ($query) use ($inspectionSearch): void {
                $term = '%'.$inspectionSearch.'%';
                $query->where(function ($search) use ($term): void {
                    $search->whereHas('vehicle', fn ($vehicle) => $vehicle
                        ->where('registration_number', 'ilike', $term)
                        ->orWhere('brand', 'ilike', $term)
                        ->orWhere('model', 'ilike', $term))
                        ->orWhereHas('rentalContract', fn ($contract) => $contract
                            ->where('contract_number', 'ilike', $term));
                });
            })
            ->latest('completed_at')
            ->latest('id')
            ->limit(self::INSPECTION_SELECTOR_LIMIT)
            ->get();
        $runs = VehicleDamagePredictionRun::query()
            ->with(['vehicle.agency', 'inspection.rentalContract', 'review.reviewer'])
            ->when($context->agencyId(), fn ($query, $agencyId) => $query->where('agency_id', $agencyId))
            ->latest('requested_at')
            ->latest('id')
            ->paginate(20);

        $provider = (string) config('intelligence.vehicle_damage_v1.execution_provider');
        $artifactReady = $modelArtifact->configuredIsValid();
        $availability = $intelligenceAccess->status(IntelligenceCapability::VehicleDamage);
        $runtimeReady = $availability->usable();

        return view('intelligence.vehicle-damages.index', [
            'inspections' => $inspections,
            'inspectionSearch' => $inspectionSearch,
            'inspectionSelectorLimit' => self::INSPECTION_SELECTOR_LIMIT,
            'runs' => $runs,
            'runtime' => [
                'enabled' => $availability->globallyEnabled && $availability->tenantAuthorized,
                'artifact_ready' => $artifactReady,
                'ready' => $runtimeReady,
                'provider' => $provider,
                'backend' => VehicleDamageContract::backend(),
            ],
            'canRun' => $runtimeReady && auth()->user()->hasPermission('prediction.damage.review'),
            'canReview' => auth()->user()->hasPermission('prediction.damage.review'),
            'contract' => [
                'backend' => VehicleDamageContract::backend(),
                'model_name' => VehicleDamageContract::modelName(),
                'model_version' => VehicleDamageContract::modelVersion(),
                'decision_threshold' => VehicleDamageContract::decisionThreshold(),
                'balanced_accuracy' => 0.857633,
                'macro_f1' => 0.852923,
                'damage_recall' => 0.867117,
                'ece' => 0.025848,
                'qualification_floor' => 0.75,
                'validation_ap' => VehicleDamageContract::VALIDATION_AP,
                'validation_ap50' => VehicleDamageContract::VALIDATION_AP50,
                'validation_ap75' => VehicleDamageContract::VALIDATION_AP75,
                'validation_precision_iou50' => VehicleDamageContract::VALIDATION_PRECISION_IOU50,
                'validation_recall_iou50' => VehicleDamageContract::VALIDATION_RECALL_IOU50,
                'scientific_gate_passed' => false,
            ],
        ]);
    }

    public function store(
        StoreVehicleDamagePredictionRequest $request,
        QueueVehicleDamagePrediction $queue,
        TenantContext $context,
    ): RedirectResponse {
        $inspection = VehicleInspection::query()
            ->with('vehicle')
            ->where('inspection_type', InspectionType::Return->value)
            ->where('status', InspectionStatus::Completed->value)
            ->when($context->agencyId(), fn ($query, $agencyId) => $query->where('agency_id', $agencyId))
            ->findOrFail((int) $request->validated('vehicle_inspection_id'));
        $image = $request->file('image');
        abort_unless($image instanceof UploadedFile, 422);

        $run = $queue->handle($inspection, $image, $request->user());

        return redirect()->route('intelligence.vehicle-damages.index')->with(
            'status',
            'L’analyse des dommages a été lancée.',
        );
    }

    public function input(
        VehicleDamagePredictionRun $damagePrediction,
        VehicleDamageInputArtifact $artifact,
        AuditRecorder $audit,
    ): StreamedResponse {
        $this->authorize('view', $damagePrediction);
        abort_unless($artifact->valid($damagePrediction), 404);

        $audit->record('prediction.vehicle_damage.input_viewed', $damagePrediction, [], [
            'run_id' => $damagePrediction->run_id,
            'vehicle_id' => $damagePrediction->vehicle_id,
            'vehicle_inspection_id' => $damagePrediction->vehicle_inspection_id,
            'input_mime' => $damagePrediction->input_mime,
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
        ]);
    }

    public function review(
        ReviewVehicleDamagePredictionRequest $request,
        VehicleDamagePredictionRun $damagePrediction,
        RecordVehicleDamagePredictionReview $record,
    ): RedirectResponse {
        $data = $request->validated();
        $note = isset($data['note']) && trim((string) $data['note']) !== ''
            ? trim((string) $data['note'])
            : null;
        $record->handle(
            $damagePrediction,
            $request->user(),
            VehicleDamageReviewDecision::from($data['decision']),
            $note,
        );

        return redirect()->route('intelligence.vehicle-damages.index')->with(
            'status',
            'Décision humaine enregistrée sans créer de dommage, frais ou responsabilité.',
        );
    }
}
