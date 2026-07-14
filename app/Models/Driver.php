<?php

namespace App\Models;

use App\Enums\VerificationStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Driver extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = ['customer_id', 'first_name', 'last_name', 'birth_date', 'licence_category', 'licence_issued_at', 'licence_expires_at', 'verification_status', 'is_primary'];

    protected $hidden = ['licence_number_encrypted', 'licence_number_hash'];

    protected function casts(): array
    {
        return ['birth_date' => 'date', 'licence_issued_at' => 'date', 'licence_expires_at' => 'date', 'verification_status' => VerificationStatus::class, 'is_primary' => 'boolean'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function isLicenceExpired(): bool
    {
        return $this->licence_expires_at->isPast();
    }

    public function isLicenceExpiringSoon(): bool
    {
        return ! $this->isLicenceExpired() && $this->licence_expires_at->lte(today()->addDays(30));
    }
}
