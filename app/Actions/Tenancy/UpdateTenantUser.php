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
            [$role, $agencyId] = $this->assignment->resolve($actor, (int) $data['role_id'], $data['agency_id'] ?? null);
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
