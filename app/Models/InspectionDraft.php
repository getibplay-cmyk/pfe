<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InspectionDraft extends Model
{
    use BelongsToTenant;

    protected $guarded = ['tenant_id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'selected_photo_ids' => 'array', 'completed_at' => 'immutable_datetime', 'revision' => 'integer'];
    }

    public function photos(): HasMany
    {
        return $this->hasMany(InspectionDraftPhoto::class);
    }
}
