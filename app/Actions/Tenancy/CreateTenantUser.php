<?php

namespace App\Actions\Tenancy;

use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\TemporaryPassword;
use App\Support\PlatformBilling\TenantPlanAccess;
use App\Support\Tenancy\TenantUserAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateTenantUser
{
    public function __construct(
        private readonly TenantUserAssignment $assignment,
        private readonly AuditRecorder $audit,
        private readonly TenantPlanAccess $planAccess,
    ) {}

    /** @return array{user: User, temporary_password: string} */
    public function handle(array $data, User $actor): array
    {
        return DB::transaction(function () use ($data, $actor): array {
            if ((bool) $data['is_active']) {
                $this->planAccess->ensureCanCreate('users', (int) $actor->tenant_id);
            }
            [$role, $agencyId] = $this->assignment->resolve($actor, (int) $data['role_id'], $data['agency_id'] ?? null);
            $temporaryPassword = TemporaryPassword::generate();
            $user = User::forceCreate([
                'tenant_id' => $actor->tenant_id,
                'agency_id' => $agencyId,
                'role_id' => $role->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'email_verified_at' => null,
                'password' => Hash::make($temporaryPassword),
                'is_active' => $data['is_active'],
                'must_change_password' => true,
                'is_platform_admin' => false,
            ]);
            $this->audit->record('user.created', $user, [], $user->only(['name', 'email', 'agency_id', 'role_id', 'is_active', 'must_change_password']));
            $this->audit->record('user.role.assigned', $user, [], ['role_id' => $role->id, 'agency_id' => $agencyId]);

            return ['user' => $user, 'temporary_password' => $temporaryPassword];
        });
    }
}
