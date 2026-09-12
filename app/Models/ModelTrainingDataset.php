<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ModelTrainingDataset extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['stored_path', 'sha256', 'created_by'];

    protected function casts(): array
    {
        return ['shared' => 'boolean', 'revoked_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
