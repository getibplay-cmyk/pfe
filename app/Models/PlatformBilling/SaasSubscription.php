<?php

namespace App\Models\PlatformBilling;

use App\Enums\PlatformBilling\SaasBillingInterval;
use App\Enums\PlatformBilling\TenantSubscriptionStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaasSubscription extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => TenantSubscriptionStatus::class,
            'billing_interval' => SaasBillingInterval::class,
            'price_amount' => 'decimal:2',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'trial_ends_at' => 'immutable_datetime',
            'next_renewal_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SaasPlan::class, 'saas_plan_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SaasPayment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
