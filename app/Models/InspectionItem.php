<?php

namespace App\Models;

use App\Enums\InspectionItemCondition;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['vehicle_inspection_id', 'item_code', 'label', 'condition', 'notes'];

    protected function casts(): array
    {
        return ['condition' => InspectionItemCondition::class];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(VehicleInspection::class, 'vehicle_inspection_id');
    }
}
