<?php

namespace App\Support\Tenancy;

use App\Models\Agency;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\Collection;

class TenantUserAssignment
{
    public function __construct(
        private readonly RoleGovernance $governance,
        private readonly AuditRecorder $audit,
    ) {}

    public function availableRoles(User $actor, ?int $agencyId): Collection
    {
        $effectiveAgencyId = $actor->isAgencyManager() ? $actor->agency_id : $agencyId;

        return $this->governance->assignableRoles($actor, $effectiveAgencyId);
    }

    /** @return array{0: Role, 1: int|null} */
    public function resolve(User $actor, int $roleId, ?int $requestedAgencyId): array
    {
        if ($actor->isAgencyManager() && $requestedAgencyId !== null && (int) $requestedAgencyId !== (int) $actor->agency_id) {
            $this->deny($actor, 'agence hors périmètre');
        }

        $effectiveAgencyId = $actor->isAgencyManager() ? $actor->agency_id : $requestedAgencyId;
        $role = $this->availableRoles($actor, $effectiveAgencyId)->firstWhere('id', $roleId);
        if (! $role) {
            $this->deny($actor, 'rôle non délégué ou supérieur au plafond');
        }

        if ($role->slug === 'tenant-owner') {
            return [$role, null];
        }

        $agencyId = $effectiveAgencyId;
        abort_unless($agencyId, 422, 'Une agence active du tenant est obligatoire.');
        Agency::query()->whereKey($agencyId)->where('is_active', true)->firstOrFail();

        return [$role, (int) $agencyId];
    }

    private function deny(User $actor, string $reason): never
    {
        $this->audit->record('user.assignment.denied', $actor, [], ['reason' => $reason]);
        abort(403, 'Cette affectation de rôle ou d’agence n’est pas autorisée.');
    }
}
