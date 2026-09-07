<?php

namespace App\Models\Platform;

use App\Enums\Platform\TenantOnboardingInvitationStatus;
use App\Models\PlatformBilling\SaasPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantOnboardingInvitation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return [
            'status' => TenantOnboardingInvitationStatus::class,
            'trial_days' => 'integer',
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SaasPlan::class, 'saas_plan_id');
    }

    public function acceptedTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'accepted_tenant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
