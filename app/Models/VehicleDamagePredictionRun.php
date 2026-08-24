<?php

namespace App\Models;

use App\Enums\VehicleDamagePredictionStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Intelligence\VehicleDamage\VehicleDamageContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VehicleDamagePredictionRun extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'agency_id',
        'run_id',
        'vehicle_inspection_id',
        'vehicle_id',
        'requested_by',
        'status',
        'failure_code',
        'input_mime',
        'input_extension',
        'input_bytes',
        'input_sha256',
        'input_stored_path',
        'input_width',
        'input_height',
        'quality_status',
        'quality_reasons',
        'quality_metrics',
        'evaluated_patches',
        'max_probability_damage',
        'suggested_damage',
        'candidate_regions',
        'model_name',
        'model_version',
        'model_artifact_sha256',
        'model_card_sha256',
        'decision_threshold',
        'operational_effect',
        'requested_at',
        'started_at',
        'finished_at',
    ];

    protected $hidden = [
        'input_sha256',
        'input_stored_path',
        'model_artifact_sha256',
        'model_card_sha256',
        'quality_metrics',
    ];

    protected function casts(): array
    {
        return [
            'status' => VehicleDamagePredictionStatus::class,
            'input_bytes' => 'integer',
            'input_width' => 'integer',
            'input_height' => 'integer',
            'quality_reasons' => 'array',
            'quality_metrics' => 'array',
            'evaluated_patches' => 'integer',
            'max_probability_damage' => 'decimal:7',
            'suggested_damage' => 'boolean',
            'candidate_regions' => 'array',
            'decision_threshold' => 'decimal:3',
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

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(VehicleInspection::class, 'vehicle_inspection_id');
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
        return $this->hasOne(VehicleDamagePredictionReview::class);
    }

    public function outcomeLabel(): string
    {
        if ($this->status !== VehicleDamagePredictionStatus::Succeeded) {
            return 'Résultat indisponible';
        }
        if ($this->quality_status === 'abstained') {
            return 'Nouvelle photo recommandée';
        }

        return $this->suggested_damage
            ? 'Zone candidate à vérifier'
            : 'Aucune zone candidate au seuil gelé';
    }

    public function qualityReasonLabels(): array
    {
        return collect($this->quality_reasons ?? [])
            ->map(fn (string $reason): string => VehicleDamageContract::qualityReasonLabel($reason))
            ->all();
    }

    public function failureLabel(): ?string
    {
        if ($this->failure_code === null) {
            return null;
        }

        return match ($this->failure_code) {
            'RUN_STALE_RECOVERED' => 'L’analyse précédente a expiré et a été fermée.',
            'RUN_ACTOR_NOT_AUTHORIZED' => 'L’utilisateur demandeur n’est plus autorisé.',
            'RETURN_INSPECTION_UNAVAILABLE' => 'L’inspection de retour n’est plus disponible dans le périmètre autorisé.',
            'MODEL_ARTIFACT_INVALID' => 'Le modèle dommages privé est absent ou son intégrité est invalide.',
            'INPUT_ARTIFACT_INVALID' => 'La photo privée est absente ou son intégrité a changé.',
            'RUNTIME_CONFIGURATION_INVALID' => 'La configuration du runtime dommages est invalide.',
            'QUEUE_DISPATCH_FAILED' => 'La queue Intelligence n’a pas accepté cette analyse.',
            'DAMAGE_PROCESS_TIMEOUT' => 'L’inférence a dépassé le délai autorisé.',
            'DAMAGE_PROCESS_FAILED', 'DAMAGE_PROCESS_START_FAILED' => 'Python ou ONNX Runtime n’a pas terminé l’inférence.',
            'DAMAGE_OUTPUT_INVALID', 'DAMAGE_OUTPUT_JSON_INVALID', 'DAMAGE_OUTPUT_CONTRACT_INVALID',
            'DAMAGE_OUTPUT_QUALITY_INVALID', 'DAMAGE_OUTPUT_SCAN_INVALID',
            'DAMAGE_OUTPUT_RESULT_INVALID', 'DAMAGE_OUTPUT_POLICY_MISMATCH' => 'La sortie ONNX ne respecte pas le contrat fermé.',
            default => 'L’analyse des dommages a échoué sans modifier l’inspection ni le véhicule.',
        };
    }
}
