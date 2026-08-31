<?php

namespace App\Models;

use App\Enums\RentalUsageAnomalyRunStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Intelligence\RentalUsageAnomaly\RentalUsageAnomalyContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RentalUsageAnomalyRun extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'agency_id',
        'run_id',
        'intelligence_dataset_export_run_id',
        'requested_by',
        'status',
        'failure_code',
        'data_status',
        'source_row_count',
        'minimum_rows',
        'default_budget_basis_points',
        'budget_results',
        'candidate_count',
        'primary_model',
        'primary_version',
        'challenger_model',
        'challenger_version',
        'random_state',
        'runtime_sha256',
        'compute',
        'operational_effect',
        'requested_at',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RentalUsageAnomalyRunStatus::class,
            'source_row_count' => 'integer',
            'minimum_rows' => 'integer',
            'default_budget_basis_points' => 'integer',
            'budget_results' => 'array',
            'candidate_count' => 'integer',
            'random_state' => 'integer',
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

    public function exportRun(): BelongsTo
    {
        return $this->belongsTo(
            IntelligenceDatasetExportRun::class,
            'intelligence_dataset_export_run_id',
        );
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function results(): HasMany
    {
        return $this->hasMany(RentalUsageAnomalyResult::class);
    }

    /** @param  Builder<RentalUsageAnomalyRun>  $query */
    public function scopeSucceededUsable(Builder $query): Builder
    {
        return $query
            ->where('status', RentalUsageAnomalyRunStatus::Succeeded->value)
            ->where('data_status', 'usable')
            ->where('default_budget_basis_points', RentalUsageAnomalyContract::DEFAULT_BUDGET_BASIS_POINTS)
            ->where('primary_model', RentalUsageAnomalyContract::PRIMARY_MODEL)
            ->where('primary_version', RentalUsageAnomalyContract::PRIMARY_VERSION)
            ->where('operational_effect', RentalUsageAnomalyContract::OPERATIONAL_EFFECT);
    }

    public function failureLabel(): ?string
    {
        if ($this->failure_code === null) {
            return null;
        }

        return match ($this->failure_code) {
            'RUN_STALE_RECOVERED' => 'L’analyse précédente a expiré et a été fermée.',
            'RUN_ACTOR_NOT_AUTHORIZED' => 'L’utilisateur demandeur n’est plus autorisé.',
            'SOURCE_SNAPSHOT_INVALID' => 'Les données nécessaires à l’analyse ne sont plus disponibles.',
            'SOURCE_CONTRACT_UNAVAILABLE' => 'Un contrat source n’est plus disponible dans le périmètre autorisé.',
            'RUNTIME_CONFIGURATION_INVALID' => 'Le service d’analyse des usages atypiques n’est pas correctement configuré.',
            'QUEUE_DISPATCH_FAILED' => 'La demande d’analyse n’a pas pu être prise en charge.',
            'ANOMALY_PROCESS_TIMEOUT' => 'L’analyse a dépassé le délai autorisé.',
            'ANOMALY_PROCESS_FAILED', 'ANOMALY_PROCESS_START_FAILED' => 'Le service n’a pas terminé l’analyse des usages atypiques.',
            'ANOMALY_OUTPUT_INVALID', 'ANOMALY_OUTPUT_JSON_INVALID',
            'ANOMALY_OUTPUT_CONTRACT_INVALID' => 'Le résultat reçu n’a pas pu être vérifié.',
            default => 'L’analyse a échoué sans modifier aucun contrat ni élément financier.',
        };
    }
}
