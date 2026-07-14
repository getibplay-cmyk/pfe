<?php

namespace App\Support\Tenancy;

use App\Exceptions\MissingTenantContextException;
use App\Models\Tenant;
use App\Models\User;
use Closure;

class TenantContext
{
    private ?int $tenantId = null;

    private ?int $agencyId = null;

    public function setFromUser(User $user): void
    {
        if ($user->is_platform_admin || $user->tenant_id === null) {
            throw new MissingTenantContextException;
        }

        $this->tenantId = $user->tenant_id;
        $this->agencyId = $user->agency_id;
    }

    public function set(Tenant|int $tenant, ?int $agencyId = null): void
    {
        $this->tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;
        $this->agencyId = $agencyId;
    }

    public function clear(): void
    {
        $this->tenantId = null;
        $this->agencyId = null;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function tenantId(): int
    {
        return $this->tenantId ?? throw new MissingTenantContextException;
    }

    public function agencyId(): ?int
    {
        return $this->agencyId;
    }

    public function run(Tenant|int $tenant, Closure $callback, ?int $agencyId = null): mixed
    {
        $previousTenantId = $this->tenantId;
        $previousAgencyId = $this->agencyId;

        $this->set($tenant, $agencyId);

        try {
            return $callback();
        } finally {
            $this->tenantId = $previousTenantId;
            $this->agencyId = $previousAgencyId;
        }
    }
}
