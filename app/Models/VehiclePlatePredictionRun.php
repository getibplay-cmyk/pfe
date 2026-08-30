<?php

namespace App\Models;

use App\Enums\VehiclePlatePredictionStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Intelligence\VehiclePlate\VehiclePlateDetectorContract;
use App\Support\Intelligence\VehiclePlate\VehiclePlateHybridContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VehiclePlatePredictionRun extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'agency_id',
        'run_id',
        'vehicle_id',
        'requested_by',
        'status',
        'failure_code',
        'input_kind',
        'input_mime',
        'input_extension',
        'input_bytes',
        'input_width',
        'input_height',
        'input_sha256',
        'input_stored_path',
        'detector_model_name',
        'detector_checkpoint_sha256',
        'detector_threshold',
        'detector_padding_ratio',
        'detector_confidence',
        'detector_candidate_count',
        'detector_bbox',
        'crop_mime',
        'crop_extension',
        'crop_bytes',
        'crop_width',
        'crop_height',
        'crop_sha256',
        'crop_stored_path',
        'crop_bbox',
        'suggestion_status',
        'suggested_canonical',
        'display_text',
        'confidence',
        'suggestion_source',
        'fallback_executed',
        'model_name',
        'result_schema_version',
        'fallback_version',
        'operational_effect',
        'requested_at',
        'started_at',
        'finished_at',
    ];

    protected $hidden = [
        'input_sha256',
        'input_stored_path',
        'detector_checkpoint_sha256',
        'detector_bbox',
        'crop_sha256',
        'crop_stored_path',
        'crop_bbox',
    ];

    protected function casts(): array
    {
        return [
            'status' => VehiclePlatePredictionStatus::class,
            'input_bytes' => 'integer',
            'input_width' => 'integer',
            'input_height' => 'integer',
            'detector_threshold' => 'decimal:7',
            'detector_padding_ratio' => 'decimal:4',
            'detector_confidence' => 'decimal:7',
            'detector_candidate_count' => 'integer',
            'detector_bbox' => 'array',
            'crop_bytes' => 'integer',
            'crop_width' => 'integer',
            'crop_height' => 'integer',
            'crop_bbox' => 'array',
            'confidence' => 'decimal:7',
            'fallback_executed' => 'boolean',
            'requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'run_id';
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function review(): HasOne
    {
        return $this->hasOne(VehiclePlatePredictionReview::class);
    }

    public function hasCompleteSuggestion(): bool
    {
        return $this->status === VehiclePlatePredictionStatus::Succeeded
            && is_string($this->suggested_canonical)
            && VehiclePlateHybridContract::isCanonical($this->suggested_canonical);
    }

    public function usesDetector(): bool
    {
        return $this->input_kind === VehiclePlateDetectorContract::FULL_IMAGE;
    }

    public function hasDetectedCrop(): bool
    {
        return $this->usesDetector()
            && $this->status === VehiclePlatePredictionStatus::Succeeded
            && is_string($this->crop_stored_path);
    }

    public function inputKindLabel(): string
    {
        return $this->usesDetector() ? 'Photo complète + détection' : 'Crop manuel';
    }

    public function suggestionLabel(): string
    {
        return match ($this->suggestion_status) {
            'complete_primary_suggestion' => 'Lecture complète du crop',
            'complete_segmented_suggestion' => 'Lecture complète par zones',
            'ambiguous_segmented_suggestion' => 'Lecture complète mais ambiguë',
            'partial_segmented_suggestion' => 'Lecture partielle à corriger',
            'empty_suggestion' => 'Aucun caractère exploitable',
            default => 'Résultat indisponible',
        };
    }

    public function failureLabel(): ?string
    {
        if ($this->failure_code === null) {
            return null;
        }

        return match ($this->failure_code) {
            'RUN_STALE_RECOVERED' => 'L’analyse précédente a expiré et a été fermée.',
            'RUN_ACTOR_NOT_AUTHORIZED' => 'L’utilisateur demandeur n’est plus autorisé.',
            'VEHICLE_UNAVAILABLE' => 'Le véhicule n’est plus disponible dans le périmètre autorisé.',
            'INPUT_ARTIFACT_INVALID' => 'L’image privée est absente ou son intégrité a changé.',
            'RUNTIME_CONFIGURATION_INVALID' => 'La configuration du runtime OCR local est invalide.',
            'OCR_INPUT_BOUNDARY_INVALID' => 'L’OCR a refusé une image qui n’est pas le crop privé attendu.',
            'DETECTOR_RUNTIME_CONFIGURATION_INVALID', 'DETECTOR_ARTIFACT_INVALID' => 'Le détecteur privé local ou son empreinte est invalide.',
            'DETECTOR_PROCESS_TIMEOUT' => 'Le détecteur local a dépassé le délai autorisé.',
            'DETECTOR_PROCESS_FAILED', 'DETECTOR_PROCESS_START_FAILED' => 'Le détecteur local n’a pas terminé l’analyse.',
            'DETECTOR_CROP_STORE_FAILED' => 'Le crop détecté n’a pas pu être conservé dans le stockage privé.',
            'DETECTOR_OUTPUT_INVALID', 'DETECTOR_OUTPUT_JSON_INVALID',
            'DETECTOR_OUTPUT_CONTRACT_INVALID', 'DETECTOR_OUTPUT_POLICY_MISMATCH',
            'DETECTOR_OUTPUT_BBOX_INVALID', 'DETECTOR_OUTPUT_CROP_INVALID' => 'La sortie du détecteur ne respecte pas le contrat fermé.',
            'PLATE_NOT_DETECTED' => 'Aucune plaque n’a été localisée. Recadrez manuellement la plaque et relancez en mode crop.',
            'PLATE_DETECTION_AMBIGUOUS' => 'Plusieurs plaques possibles ont été trouvées. Utilisez un crop manuel pour choisir la bonne.',
            'QUEUE_DISPATCH_FAILED' => 'La queue Intelligence n’a pas accepté cette analyse.',
            'PLATE_PROCESS_TIMEOUT' => 'L’OCR local a dépassé le délai autorisé.',
            'PLATE_PROCESS_FAILED', 'PLATE_PROCESS_START_FAILED' => 'Le runtime PaddleOCR local n’a pas terminé l’analyse.',
            'PLATE_TEMPORARY_CLEANUP_FAILED' => 'Le nettoyage du traitement local sécurisé n’a pas pu être confirmé.',
            'PLATE_OUTPUT_INVALID', 'PLATE_OUTPUT_JSON_INVALID', 'PLATE_OUTPUT_CONTRACT_INVALID',
            'PLATE_OUTPUT_ROW_INVALID', 'PLATE_OUTPUT_SUGGESTION_INVALID',
            'PLATE_OUTPUT_POLICY_MISMATCH', 'PLATE_OUTPUT_COMPONENTS_INVALID',
            'PLATE_OUTPUT_OBSERVATIONS_INVALID' => 'La sortie OCR ne respecte pas le contrat fermé.',
            default => 'L’analyse de plaque a échoué sans modifier le véhicule.',
        };
    }
}
