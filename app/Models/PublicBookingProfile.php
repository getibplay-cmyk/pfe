<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PublicBookingProfile extends Model
{
    use BelongsToTenant;

    protected $guarded = ['tenant_id', 'id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
