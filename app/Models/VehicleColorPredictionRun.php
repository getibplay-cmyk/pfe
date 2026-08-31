<?php

namespace App\Models;

use App\Enums\VehicleColorPredictionStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Intelligence\VehicleColor\VehicleColorContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VehicleColorPredictionRun extends Model
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
        'suggested_color',
        'confidence',
        'model_accepted',
        'probabilities',
        'model_name',
        'model_version',
        'model_artifact_sha256',
        'metadata_sha256',
        'accepted_threshold',
        'operational_effect',
        'requested_at',
        'started_at',
        'finished_at',
    ];

    protected $hidden = [
        'input_sha256',
        'input_stored_path',
        'model_artifact_sha256',
        'metadata_sha256',
        'probabilities',
    ];

    protected function casts(): array
    {
        return [
            'status' => VehicleColorPredictionStatus::class,
            'input_bytes' => 'integer',
            'confidence' => 'decimal:7',
            'model_accepted' => 'boolean',
            'probabilities' => 'array',
            'accepted_threshold' => 'decimal:3',
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
        return $this->hasOne(VehicleColorPredictionReview::class);
    }

    public function outcomeLabel(): string
    {
        if ($this->status !== VehicleColorPredictionStatus::Succeeded) {
            return 'Résultat indisponible';
        }

        return $this->hasDisplayableCandidate()
            ? VehicleColorContract::label($this->suggested_color)
            : 'Résultat non exploitable';
    }

    public function hasDisplayableCandidate(): bool
    {
        if ($this->status !== VehicleColorPredictionStatus::Succeeded
            || ! is_string($this->suggested_color)
            || ! in_array($this->suggested_color, VehicleColorContract::SUPPORTED_COLORS, true)
            || ! is_numeric($this->confidence)) {
            return false;
        }

        $confidence = (float) $this->confidence;

        return is_finite($confidence) && $confidence >= 0 && $confidence <= 1;
    }

    public function hasLowConfidenceCandidate(): bool
    {
        return $this->hasDisplayableCandidate()
            && (float) $this->confidence < VehicleColorContract::CONSULTATIVE_DISPLAY_THRESHOLD;
    }

    public function consultativeStatus(): string
    {
        if ($this->status !== VehicleColorPredictionStatus::Succeeded) {
            return 'vehicle_color_consultative_unavailable';
        }

        if ($this->model_accepted) {
            return 'vehicle_color_consultative_scientific_threshold_reached';
        }

        return $this->hasDisplayableCandidate()
            ? 'vehicle_color_consultative_candidate_to_review'
            : 'vehicle_color_consultative_not_exploitable';
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
            'MODEL_ARTIFACT_INVALID' => 'Le service de suggestion de couleur n’est pas correctement installé.',
            'INPUT_ARTIFACT_INVALID' => 'La photo privée n’est plus disponible ou a été modifiée.',
            'RUNTIME_CONFIGURATION_INVALID' => 'Le service de suggestion de couleur n’est pas correctement configuré.',
            'QUEUE_DISPATCH_FAILED' => 'La demande d’analyse n’a pas pu être prise en charge.',
            'COLOR_PROCESS_TIMEOUT' => 'L’analyse de la couleur a dépassé le délai autorisé.',
            'COLOR_PROCESS_FAILED' => 'Le service n’a pas terminé l’analyse de la couleur.',
            'COLOR_OUTPUT_INVALID', 'COLOR_OUTPUT_JSON_INVALID', 'COLOR_OUTPUT_CONTRACT_INVALID',
            'COLOR_OUTPUT_RESULT_INVALID', 'COLOR_OUTPUT_PROBABILITIES_INVALID',
            'COLOR_OUTPUT_POLICY_MISMATCH' => 'Le résultat reçu n’a pas pu être vérifié.',
            default => 'L’analyse de la couleur n’a pas abouti. La fiche véhicule reste inchangée.',
        };
    }
}
