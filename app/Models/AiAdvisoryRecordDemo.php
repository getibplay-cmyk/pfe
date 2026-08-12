<?php

namespace App\Models;

use App\Enums\J11AdvisoryModule;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiAdvisoryRecordDemo extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'ai_advisory_records_demo';

    protected $fillable = [
        'agency_id',
        'external_record_id',
        'module_id',
        'contract_version',
        'source_kind',
        'payload',
        'fingerprint',
        'validation_status',
        'operational_effect',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'module_id' => J11AdvisoryModule::class,
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function idempotency(): HasOne
    {
        return $this->hasOne(AiIdempotencyKeyDemo::class, 'ai_advisory_record_demo_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(AiHumanDecisionDemo::class, 'ai_advisory_record_demo_id')->latest('id');
    }
}
