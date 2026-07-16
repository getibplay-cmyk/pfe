<?php

namespace App\Support\Tenancy;

use App\Models\Agency;
use App\Models\Role;
use App\Models\User;

class TenantUserAssignment
{
    /** @return array{0: Role, 1: int|null} */
    public function resolve(User $actor, int $roleId, ?int $requestedAgencyId): array
    {
        $role = Role::query()
            ->whereKey($roleId)
            ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $actor->tenant_id))
            ->firstOrFail();

        abort_if($role->slug === 'platform-admin', 403);
        if ($actor->isAgencyManager()) {
            abort_unless(in_array($role->slug, ['rental-agent', 'fleet-manager', 'viewer-auditor'], true), 403);
            abort_if($requestedAgencyId !== null && $requestedAgencyId !== $actor->agency_id, 403);
        }

        if ($role->slug === 'tenant-owner') {
            return [$role, null];
        }

        $agencyId = $actor->agency_id ?? $requestedAgencyId;
        abort_unless($agencyId, 422, 'Une agence active du tenant est obligatoire.');
        Agency::query()->whereKey($agencyId)->where('is_active', true)->firstOrFail();

        return [$role, (int) $agencyId];
    }
}
