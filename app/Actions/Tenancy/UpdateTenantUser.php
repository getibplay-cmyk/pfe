<?php

namespace App\Actions\Tenancy;

use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantUserAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateTenantUser
{
    public function __construct(
        private readonly TenantUserAssignment $assignment,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(User $subject, array $data, User $actor): User
    {
        return DB::transaction(function () use ($subject, $data, $actor): User {
            $locked = User::query()->lockForUpdate()->findOrFail($subject->id);
            abort_unless($locked->tenant_id === $actor->tenant_id, 403);
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
            $locked->forceFill([
                'agency_id' => $agencyId,
                'role_id' => $role->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'is_active' => $data['is_active'],
            ])->save();

            if (! $locked->is_active) {
                DB::table('sessions')->where('user_id', $locked->id)->delete();
            }
            $this->audit->record('user.updated', $locked, $old, $locked->only(array_keys($old)));
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
            throw ValidationException::withMessages(['is_active' => 'Le dernier Tenant Owner actif ne peut pas être désactivé ou rétrogradé.']);
        }
    }
}
