<?php

namespace App\Actions\Tenancy;

use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\PlatformBilling\TenantPlanAccess;
use App\Support\Tenancy\TenantUserAssignment;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class UpdateTenantUser
{
    public function __construct(
        private readonly TenantUserAssignment $assignment,
        private readonly AuditRecorder $audit,
        private readonly TenantPlanAccess $planAccess,
    ) {}

    public function handle(User $subject, array $data, User $actor): User
    {
        return DB::transaction(function () use ($subject, $data, $actor): User {
            $locked = User::query()->lockForUpdate()->findOrFail($subject->id);
            abort_unless($locked->tenant_id === $actor->tenant_id, 403);
            if (! $locked->is_active && (bool) $data['is_active']) {
                $this->planAccess->ensureCanCreate('users', (int) $locked->tenant_id);
            }
            if (! $actor->isTenantOwner() && ! $actor->isAgencyManager()) {
                abort_unless((int) $data['role_id'] === (int) $locked->role_id, 403);
                abort_unless(($data['agency_id'] ?? null) === $locked->agency_id, 403);
                $role = $locked->role;
                $agencyId = $locked->agency_id;
            } else {
                [$role, $agencyId] = $this->assignment->resolve($actor, (int) $data['role_id'], $data['agency_id'] ?? null);
            }
            $this->protectLastOwner($locked, $role, $data['is_active']);

            $old = $locked->only(['name', 'email', 'agency_id', 'role_id', 'is_active']);
            $emailChanged = $locked->email !== $data['email'];
            $securityChanged = $emailChanged || (int) $locked->role_id !== (int) $role->id
                || $locked->agency_id !== $agencyId || (bool) $locked->is_active !== (bool) $data['is_active'];
            if ($securityChanged) {
                Password::deleteToken($locked);
            }
            $locked->forceFill([
                'agency_id' => $agencyId,
                'role_id' => $role->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'email_verified_at' => $emailChanged ? null : $locked->email_verified_at,
                'is_active' => $data['is_active'],
                ...($securityChanged ? [
                    'security_version' => $locked->security_version + 1,
                    'remember_token' => null,
                    'pending_email' => null,
                    'pending_email_token_hash' => null,
                    'pending_email_expires_at' => null,
                ] : []),
            ])->save();

            if ($securityChanged) {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $locked->id)->delete();
            }
            $this->audit->record('user.updated', $locked,
                Arr::only($old, ['agency_id', 'role_id', 'is_active']),
                [...$locked->only(['agency_id', 'role_id', 'is_active']), 'profile_changed' => true, 'email_changed' => $emailChanged]);
            if ((int) $old['role_id'] !== (int) $locked->role_id || (int) $old['agency_id'] !== (int) $locked->agency_id) {
                $this->audit->record('user.role.assigned', $locked, ['role_id' => $old['role_id'], 'agency_id' => $old['agency_id']], ['role_id' => $locked->role_id, 'agency_id' => $locked->agency_id]);
            }
            if ((bool) $old['is_active'] !== (bool) $locked->is_active) {
                $this->audit->record($locked->is_active ? 'user.activated' : 'user.deactivated', $locked, ['is_active' => $old['is_active']], ['is_active' => $locked->is_active]);
            }

            return $locked;
        });
    }

    private function protectLastOwner(User $subject, Role $newRole, bool $willBeActive): void
    {
        if ($subject->role?->slug !== 'tenant-owner' || ($newRole->slug === 'tenant-owner' && $willBeActive)) {
            return;
        }

        $activeOwners = User::query()
            ->where('tenant_id', $subject->tenant_id)
            ->where('role_id', $subject->role_id)
            ->where('is_active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);

        if ($activeOwners->where('id', '!=', $subject->id)->isEmpty()) {
            throw ValidationException::withMessages(['is_active' => __('Le dernier Tenant Owner actif ne peut pas être désactivé ou rétrogradé.')]);
        }
    }
}
