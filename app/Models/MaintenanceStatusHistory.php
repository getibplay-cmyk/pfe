<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceStatusHistory extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['maintenance_order_id', 'from_status', 'to_status', 'reason', 'changed_by'];

    public function maintenanceOrder(): BelongsTo
    {
        return $this->belongsTo(MaintenanceOrder::class);
    }
}
