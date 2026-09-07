<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OnboardingImport extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['tenant_id'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'expires_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }
}
