<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class InsurancePolicy extends Model
{
    use BelongsToTenant;

    protected $fillable = ['agency_id', 'vehicle_id', 'insurance_company_id', 'policy_type', 'starts_at', 'ends_at', 'premium_amount', 'deductible_amount', 'currency', 'status', 'document_id'];

    protected $hidden = ['policy_number_encrypted', 'policy_number_hash'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_date', 'ends_at' => 'immutable_date', 'premium_amount' => 'decimal:2', 'deductible_amount' => 'decimal:2'];
    }

    public function setPolicyNumber(string $number): self
    {
        $normalized = mb_strtoupper(trim($number));
        $tenantId = app(TenantContext::class)->tenantId();
        $this->policy_number_encrypted = Crypt::encryptString($normalized);
        $this->policy_number_hash = hash_hmac('sha256', $tenantId.'|'.$normalized, (string) config('app.key'));

        return $this;
    }

    public function maskedPolicyNumber(): string
    {
        $value = Crypt::decryptString($this->policy_number_encrypted);

        return str_repeat('•', max(0, mb_strlen($value) - 4)).mb_substr($value, -4);
    }

    public function scopeExpiring(Builder $query, int $days = 30): Builder
    {
        return $query->whereIn('status', ['active', 'expired'])->whereDate('ends_at', '<=', today()->addDays($days));
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(InsuranceCompany::class, 'insurance_company_id');
    }

    public function coverages(): HasMany
    {
        return $this->hasMany(InsurancePolicyCoverage::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(InsuranceClaim::class);
    }
}
