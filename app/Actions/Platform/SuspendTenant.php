<?php

namespace App\Actions\Platform;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SuspendTenant
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(Tenant $tenant, string $reason, int $actorId): Tenant
    {
        return DB::transaction(function () use ($tenant, $reason, $actorId): Tenant {
            $locked = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            if ($locked->status !== TenantStatus::Active) {
                throw ValidationException::withMessages(['status' => 'Seul un tenant actif peut être suspendu.']);
            }

            $old = ['status' => $locked->status->value];
            $locked->forceFill([
                'status' => TenantStatus::Suspended,
                'suspension_reason' => $reason,
                'suspended_at' => now(),
                'suspended_by' => $actorId,
            ])->save();

            $userIds = DB::table('users')->where('tenant_id', $locked->id)->pluck('id');
            DB::table('sessions')->whereIn('user_id', $userIds)->delete();
            $this->audit->record('platform.tenant.suspended', $locked, $old, [
                'status' => TenantStatus::Suspended->value,
                'reason' => $reason,
            ]);

            return $locked;
        });
    }
}
