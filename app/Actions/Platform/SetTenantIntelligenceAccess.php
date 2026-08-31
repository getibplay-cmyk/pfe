<?php

namespace App\Actions\Platform;

use App\Enums\IntelligenceCapability;
use App\Models\Tenant;
use App\Models\TenantIntelligenceAccess;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class SetTenantIntelligenceAccess
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(
        Tenant $tenant,
        IntelligenceCapability $capability,
        bool $enabled,
        User $actor,
    ): TenantIntelligenceAccess {
        if (! $actor->is_active || ! $actor->is_platform_admin || $actor->tenant_id !== null) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($tenant, $capability, $enabled, $actor): TenantIntelligenceAccess {
            $lockedTenant = Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();
            $access = TenantIntelligenceAccess::query()
                ->where('tenant_id', $lockedTenant->getKey())
                ->where('capability', $capability->value)
                ->lockForUpdate()
                ->first();
            $previous = $access?->enabled;

            if ($access === null) {
                $access = new TenantIntelligenceAccess;
                $access->forceFill([
                    'tenant_id' => $lockedTenant->getKey(),
                    'capability' => $capability,
                    'enabled' => false,
                    'changed_at' => now(),
                ]);
            }
            if ($access->exists && $previous === $enabled) {
                return $access;
            }

            $access->forceFill([
                'enabled' => $enabled,
                'updated_by' => $actor->getKey(),
                'changed_at' => now(),
            ])->save();

            $this->audit->record('platform.intelligence_access.updated', $access, [
                'capability' => $capability->value,
                'enabled' => $previous,
            ], [
                'capability' => $capability->value,
                'enabled' => $enabled,
            ]);

            return $access->refresh();
        }, 3);
    }
}
