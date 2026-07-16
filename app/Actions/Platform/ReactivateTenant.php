<?php

namespace App\Actions\Platform;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReactivateTenant
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(Tenant $tenant): Tenant
    {
        return DB::transaction(function () use ($tenant): Tenant {
            $locked = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            if ($locked->status !== TenantStatus::Suspended) {
                throw ValidationException::withMessages(['status' => 'Seul un tenant suspendu peut être réactivé.']);
            }

            $reason = $locked->suspension_reason;
            $locked->forceFill([
                'status' => TenantStatus::Active,
                'suspension_reason' => null,
                'suspended_at' => null,
                'suspended_by' => null,
            ])->save();
            $this->audit->record('platform.tenant.reactivated', $locked, [
                'status' => TenantStatus::Suspended->value,
                'reason' => $reason,
            ], ['status' => TenantStatus::Active->value]);

            return $locked;
        });
    }
}
