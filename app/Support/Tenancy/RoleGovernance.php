<?php

namespace App\Support\Tenancy;

use App\Models\Agency;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RoleGovernance
{
    private const FORBIDDEN_CUSTOM_PERMISSIONS = ['role.manage', 'role.delegate'];

    public function __construct(private readonly AuditRecorder $audit) {}

    public function rolesVisibleTo(User $actor): Collection
    {
        return Role::query()
            ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $actor->tenant_id))
            ->withCount('users')
            ->with('permissions:id,name,slug,group')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();
    }

    public function assignableRoles(User $actor, ?int $agencyId): Collection
    {
        if ($actor->isTenantOwner()) {
            return Role::query()
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $actor->tenant_id))
                ->where('slug', '!=', 'platform-admin')
                ->when($agencyId !== null, fn ($query) => $query->where('slug', '!=', 'tenant-owner'))
                ->orderBy('name')
                ->get();
        }

        abort_unless($actor->isAgencyManager() && $actor->agency_id === $agencyId, 403);
        $permissionCeiling = $actor->role->permissions->pluck('id');

        return Role::query()
            ->where('is_active', true)
            ->whereIn('id', DB::table('role_agency_delegations')
                ->where('tenant_id', $actor->tenant_id)
                ->where('agency_id', $actor->agency_id)
                ->select('role_id'))
            ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $actor->tenant_id))
            ->whereNotIn('slug', ['platform-admin', 'tenant-owner'])
            ->whereDoesntHave('permissions', fn ($query) => $query->whereNotIn('permissions.id', $permissionCeiling))
            ->orderBy('name')
            ->get();
    }

    public function create(array $data, User $actor): Role
    {
        abort_unless($actor->isTenantOwner() && $actor->hasPermission('role.manage'), 403);
        $this->ensureUniqueName($data['name'], $actor->tenant_id);

        return DB::transaction(function () use ($data, $actor): Role {
            $permissions = $this->validatedPermissions($data['permission_ids'] ?? []);
            $slugBase = Str::slug($data['name']) ?: 'role-personnalise';
            $slug = $slugBase;
            $suffix = 2;
            while (Role::query()->where('tenant_id', $actor->tenant_id)->where('slug', $slug)->exists()) {
                $slug = $slugBase.'-'.$suffix++;
            }

            $role = Role::query()->forceCreate([
                'tenant_id' => $actor->tenant_id,
                'name' => $data['name'],
                'slug' => $slug,
                'is_system' => false,
                'is_active' => true,
                'created_by' => $actor->id,
            ]);
            $role->permissions()->sync($permissions->modelKeys());
            $this->audit->record('role.created', $role, [], ['name' => $role->name, 'permission_count' => $permissions->count()]);

            return $role;
        });
    }

    public function update(Role $role, array $data, User $actor): Role
    {
        abort_unless($actor->can('update', $role), 403);
        $this->ensureUniqueName($data['name'], $actor->tenant_id, $role->id);

        return DB::transaction(function () use ($role, $data, $actor): Role {
            $locked = Role::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();
            abort_unless(! $locked->is_system && $locked->tenant_id === $actor->tenant_id, 403);
            $permissions = $this->validatedPermissions($data['permission_ids'] ?? []);
            $oldPermissionIds = $locked->permissions()->pluck('permissions.id')->all();
            $willBeActive = (bool) $data['is_active'];

            if (! $willBeActive && $locked->users()->exists()) {
                $replacementId = $data['replacement_role_id'] ?? null;
                if (! $replacementId) {
                    throw ValidationException::withMessages(['replacement_role_id' => 'Un rôle de remplacement est obligatoire car ce rôle est encore attribué.']);
                }
                $replacement = Role::query()->whereKey($replacementId)->where('is_active', true)
                    ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $actor->tenant_id))
                    ->where('slug', '!=', 'platform-admin')->firstOrFail();
                $affected = $locked->users()->pluck('id');
                User::query()->whereIn('id', $affected)->update(['role_id' => $replacement->id]);
                $this->audit->record('role.assignments.replaced', $locked, ['role_id' => $locked->id], ['replacement_role_id' => $replacement->id, 'user_count' => $affected->count()]);
            }

            $old = ['name' => $locked->name, 'is_active' => $locked->is_active, 'permission_ids' => $oldPermissionIds];
            $locked->forceFill(['name' => $data['name'], 'is_active' => $willBeActive])->save();
            $locked->permissions()->sync($permissions->modelKeys());
            $this->audit->record('role.updated', $locked, $old, ['name' => $locked->name, 'is_active' => $locked->is_active, 'permission_ids' => $permissions->modelKeys()]);

            return $locked;
        });
    }

    public function syncDelegations(Agency $agency, array $roleIds, User $actor): void
    {
        abort_unless($actor->can('delegate', Role::class), 403);
        abort_unless($agency->tenant_id === $actor->tenant_id, 403);

        DB::transaction(function () use ($agency, $roleIds, $actor): void {
            $allowed = Role::query()->whereIn('id', $roleIds)->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $actor->tenant_id))
                ->whereNotIn('slug', ['platform-admin', 'tenant-owner'])->pluck('id')->all();
            if (count(array_unique(array_map('intval', $roleIds))) !== count($allowed)) {
                throw ValidationException::withMessages(['role_ids' => 'Un rôle sélectionné ne peut pas être délégué à cette agence.']);
            }

            $old = DB::table('role_agency_delegations')->where('tenant_id', $actor->tenant_id)->where('agency_id', $agency->id)->pluck('role_id')->all();
            DB::table('role_agency_delegations')->where('tenant_id', $actor->tenant_id)->where('agency_id', $agency->id)->whereNotIn('role_id', $allowed ?: [0])->delete();
            foreach ($allowed as $roleId) {
                DB::table('role_agency_delegations')->updateOrInsert(
                    ['tenant_id' => $actor->tenant_id, 'agency_id' => $agency->id, 'role_id' => $roleId],
                    ['delegated_by' => $actor->id, 'updated_at' => now(), 'created_at' => now()],
                );
            }
            $this->audit->record('role.delegations.updated', $agency, ['role_ids' => $old], ['role_ids' => $allowed]);
        });
    }

    private function validatedPermissions(array $permissionIds): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $permissionIds)));
        $permissions = Permission::query()->whereIn('id', $ids)
            ->where('group', '!=', 'platform')->where('slug', 'not like', 'platform.%')
            ->whereNotIn('slug', self::FORBIDDEN_CUSTOM_PERMISSIONS)->get();

        if (count($ids) !== $permissions->count()) {
            throw ValidationException::withMessages(['permission_ids' => 'Une permission sélectionnée est interdite ou inconnue.']);
        }

        return $permissions;
    }

    private function ensureUniqueName(string $name, int $tenantId, ?int $exceptRoleId = null): void
    {
        $exists = Role::query()->where('tenant_id', $tenantId)
            ->whereRaw('lower(name) = lower(?)', [trim($name)])
            ->when($exceptRoleId, fn ($query) => $query->whereKeyNot($exceptRoleId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => 'Ce nom de rôle est déjà utilisé dans votre entreprise.']);
        }
    }
}
