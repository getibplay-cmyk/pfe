<?php

namespace App\Models;

use App\Enums\DamageResponsibility;
use App\Enums\DamageStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DamageStatusHistory extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['damage_report_id', 'from_status', 'to_status', 'responsibility', 'reason', 'changed_by'];

    protected function casts(): array
    {
        return ['from_status' => DamageStatus::class, 'to_status' => DamageStatus::class, 'responsibility' => DamageResponsibility::class, 'created_at' => 'immutable_datetime'];
    }

    public function damageReport(): BelongsTo
    {
        return $this->belongsTo(DamageReport::class);
    }
}
