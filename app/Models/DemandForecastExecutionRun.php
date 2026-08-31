<?php

namespace App\Models;

use App\Enums\DemandForecastExecutionStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandForecastExecutionRun extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'agency_id',
        'run_id',
        'demand_history_export_run_id',
        'requested_by',
        'demand_forecast_run_id',
        'status',
        'failure_code',
        'model_artifact_sha256',
        'model_artifact_bytes',
        'operational_effect',
        'requested_at',
        'started_at',
        'finished_at',
    ];

    protected $hidden = ['model_artifact_sha256'];

    protected function casts(): array
    {
        return [
            'status' => DemandForecastExecutionStatus::class,
            'model_artifact_bytes' => 'integer',
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

    public function historyExport(): BelongsTo
    {
        return $this->belongsTo(DemandHistoryExportRun::class, 'demand_history_export_run_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function forecastRun(): BelongsTo
    {
        return $this->belongsTo(DemandForecastRun::class, 'demand_forecast_run_id');
    }

    public function failureLabel(): ?string
    {
        if ($this->failure_code === null) {
            return null;
        }

        return match ($this->failure_code) {
            'RUN_STALE_RECOVERED' => 'L’exécution précédente a expiré et a été fermée.',
            'RUN_ACTOR_NOT_AUTHORIZED' => 'L’utilisateur demandeur n’est plus autorisé.',
            'MODEL_ARTIFACT_INVALID', 'HISTORY_ARTIFACT_INVALID' => 'Les éléments nécessaires à la prévision ne sont plus disponibles.',
            'RUNTIME_CONFIGURATION_INVALID' => 'Le service de prévision n’est pas correctement configuré.',
            'HGB_PROCESS_TIMEOUT' => 'La prévision a dépassé le délai autorisé.',
            'HGB_PROCESS_FAILED' => 'Le service de prévision n’a pas terminé le calcul.',
            'HGB_OUTPUT_INVALID', 'HGB_OUTPUT_CONTRACT_INVALID' => 'Le résultat reçu n’a pas pu être vérifié.',
            'HGB_OUTPUT_IMPORT_FAILED' => 'Le résultat vérifié n’a pas pu être enregistré.',
            default => 'La prévision n’a pas pu être générée. Aucune donnée métier n’a été modifiée.',
        };
    }
}
