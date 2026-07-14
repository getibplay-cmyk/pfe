<?php

namespace App\Models;

use App\Enums\CustomerType;
use App\Enums\VerificationStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = ['agency_id', 'customer_type', 'first_name', 'last_name', 'company_name', 'email', 'phone', 'address', 'city', 'nationality', 'birth_date', 'identity_type', 'verification_status', 'notes'];

    protected $hidden = ['identity_number_encrypted', 'identity_number_hash'];

    protected function casts(): array
    {
        return ['customer_type' => CustomerType::class, 'verification_status' => VerificationStatus::class, 'birth_date' => 'date', 'custom_values' => 'array'];
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function displayName(): string
    {
        return $this->customer_type === CustomerType::Company ? (string) $this->company_name : trim($this->first_name.' '.$this->last_name);
    }
}
