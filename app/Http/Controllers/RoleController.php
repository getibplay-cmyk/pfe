<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleDelegationsRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Models\Agency;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAgencyDelegation;
use App\Support\Tenancy\RoleGovernance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(Request $request, RoleGovernance $governance): View
    {
        $this->authorize('viewAny', Role::class);

        return view('roles.index', ['roles' => $governance->rolesVisibleTo($request->user())]);
    }

    public function create(): View
    {
        $this->authorize('create', Role::class);

        return view('roles.form', $this->formData(new Role));
    }

    public function store(StoreRoleRequest $request, RoleGovernance $governance): RedirectResponse
    {
        $governance->create($request->validated(), $request->user());

        return redirect()->route('roles.index')->with('status', 'Rôle personnalisé créé.');
    }

    public function edit(Role $role): View
    {
        $this->authorize('update', $role);

        return view('roles.form', $this->formData($role));
    }

    public function update(UpdateRoleRequest $request, Role $role, RoleGovernance $governance): RedirectResponse
    {
        $governance->update($role, $request->validated(), $request->user());

        return redirect()->route('roles.index')->with('status', 'Rôle personnalisé mis à jour.');
    }

    public function delegations(Request $request): View
    {
        $this->authorize('delegate', Role::class);
        $agencies = Agency::query()->where('is_active', true)->with('users:id,agency_id,role_id')->orderBy('name')->get();
        $roles = Role::query()->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $request->user()->tenant_id))
            ->whereNotIn('slug', ['platform-admin', 'tenant-owner'])->orderBy('name')->get();
        $delegations = RoleAgencyDelegation::query()->get()->groupBy('agency_id')->map->pluck('role_id');

        return view('roles.delegations', compact('agencies', 'roles', 'delegations'));
    }

    public function updateDelegations(UpdateRoleDelegationsRequest $request, Agency $agency, RoleGovernance $governance): RedirectResponse
    {
        $governance->syncDelegations($agency, $request->validated('role_ids'), $request->user());

        return back()->with('status', 'Délégation des rôles mise à jour pour '.$agency->name.'.');
    }

    private function formData(Role $role): array
    {
        $role->loadMissing('permissions:id');
        $permissions = Permission::query()
            ->where('group', '!=', 'platform')->where('slug', 'not like', 'platform.%')
            ->whereNotIn('slug', ['role.manage', 'role.delegate'])
            ->orderBy('group')->orderBy('name')->get()->groupBy('group');
        $replacementRoles = Role::query()->where('is_active', true)->whereKeyNot($role->id)
            ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', auth()->user()->tenant_id))
            ->where('slug', '!=', 'platform-admin')->orderBy('name')->get();

        return compact('role', 'permissions', 'replacementRoles');
    }
}
