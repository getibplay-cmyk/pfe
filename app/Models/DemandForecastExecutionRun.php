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
            'MODEL_ARTIFACT_INVALID' => 'Le bundle J5 privé est absent ou ne correspond plus à son empreinte.',
            'HISTORY_ARTIFACT_INVALID' => 'Le snapshot privé est absent ou altéré.',
            'RUNTIME_CONFIGURATION_INVALID' => 'La configuration du runtime HGB est invalide.',
            'HGB_PROCESS_TIMEOUT' => 'Le calcul HGB a dépassé le délai autorisé.',
            'HGB_PROCESS_FAILED' => 'Python ou l’environnement HGB figé n’a pas pu terminer le calcul.',
            'HGB_OUTPUT_INVALID', 'HGB_OUTPUT_CONTRACT_INVALID' => 'La sortie HGB ne respecte pas le contrat fermé.',
            'HGB_OUTPUT_IMPORT_FAILED' => 'La sortie validée n’a pas pu être conservée.',
            default => 'L’inférence HGB a échoué sans modifier les données opérationnelles.',
        };
    }
}
