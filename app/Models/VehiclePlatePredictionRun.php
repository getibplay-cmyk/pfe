<?php

namespace App\Models;

use App\Enums\VehiclePlatePredictionStatus;
use App\Models\Concerns\BelongsToTenant;
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
        'input_mime',
        'input_extension',
        'input_bytes',
        'input_sha256',
        'input_stored_path',
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
    ];

    protected function casts(): array
    {
        return [
            'status' => VehiclePlatePredictionStatus::class,
            'input_bytes' => 'integer',
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
            'INPUT_ARTIFACT_INVALID' => 'Le crop privé est absent ou son intégrité a changé.',
            'RUNTIME_CONFIGURATION_INVALID' => 'La configuration du runtime OCR local est invalide.',
            'QUEUE_DISPATCH_FAILED' => 'La queue Intelligence n’a pas accepté cette analyse.',
            'PLATE_PROCESS_TIMEOUT' => 'L’OCR local a dépassé le délai autorisé.',
            'PLATE_PROCESS_FAILED', 'PLATE_PROCESS_START_FAILED' => 'Le runtime PaddleOCR local n’a pas terminé l’analyse.',
            'PLATE_OUTPUT_INVALID', 'PLATE_OUTPUT_JSON_INVALID', 'PLATE_OUTPUT_CONTRACT_INVALID',
            'PLATE_OUTPUT_ROW_INVALID', 'PLATE_OUTPUT_SUGGESTION_INVALID',
            'PLATE_OUTPUT_POLICY_MISMATCH', 'PLATE_OUTPUT_COMPONENTS_INVALID',
            'PLATE_OUTPUT_OBSERVATIONS_INVALID' => 'La sortie OCR ne respecte pas le contrat fermé.',
            default => 'L’analyse de plaque a échoué sans modifier le véhicule.',
        };
    }
}
