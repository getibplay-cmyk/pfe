<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Agency extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = ['code', 'name', 'email', 'phone', 'address', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function outgoingDistances(): HasMany
    {
        return $this->hasMany(AgencyDistance::class, 'from_agency_id');
    }

    public function incomingDistances(): HasMany
    {
        return $this->hasMany(AgencyDistance::class, 'to_agency_id');
    }
}
