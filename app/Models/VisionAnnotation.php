<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class VisionAnnotation extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['truth', 'source_sha256'];

    protected function casts(): array
    {
        return ['truth' => 'encrypted:array', 'matches_prediction' => 'boolean', 'was_abstained' => 'boolean', 'created_at' => 'immutable_datetime', 'revision' => 'integer'];
    }
}
