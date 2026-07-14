<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InsuranceCompany extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'email', 'phone', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function policies(): HasMany
    {
        return $this->hasMany(InsurancePolicy::class);
    }
}
