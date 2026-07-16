<?php

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateTenantUser;
use App\Actions\Tenancy\ResetTenantUserPassword;
use App\Actions\Tenancy\UpdateTenantUser;
use App\Http\Requests\StoreTenantUserRequest;
use App\Http\Requests\UpdateTenantUserRequest;
use App\Models\Agency;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class TenantUserController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $users = $this->scopedUsers($request)
            ->with(['agency', 'role'])
            ->when($request->string('q')->isNotEmpty(), fn ($query) => $query->where(fn ($search) => $search->where('name', 'ilike', '%'.$request->string('q').'%')->orWhere('email', 'ilike', '%'.$request->string('q').'%')))
            ->when($request->integer('role_id'), fn ($query, $roleId) => $query->where('role_id', $roleId))
            ->when($request->string('status')->isNotEmpty(), fn ($query) => $query->where('is_active', $request->string('status')->toString() === 'active'))
            ->orderBy('name')->paginate(20)->withQueryString();

        return view('users.index', [
            'users' => $users,
            'filterRoles' => Role::query()->whereNull('tenant_id')->orderBy('name')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', User::class);

        return view('users.form', $this->formData($request, new User));
    }

    public function store(StoreTenantUserRequest $request, CreateTenantUser $action): Response
    {
        $result = $action->handle($request->validated(), $request->user());

        return $this->temporaryPasswordResponse($result['user'], $result['temporary_password'], 'Utilisateur créé');
    }

    public function edit(Request $request, User $user): View
    {
        $this->authorize('update', $user);

        return view('users.form', $this->formData($request, $user));
    }

    public function update(UpdateTenantUserRequest $request, User $user, UpdateTenantUser $action): RedirectResponse
    {
        $action->handle($user, $request->validated(), $request->user());

        return redirect()->route('users.index')->with('status', 'Utilisateur mis à jour.');
    }

    public function resetPassword(Request $request, User $user, ResetTenantUserPassword $action): Response
    {
        $this->authorize('update', $user);
        $temporaryPassword = $action->handle($user);

        return $this->temporaryPasswordResponse($user, $temporaryPassword, 'Mot de passe réinitialisé');
    }

    private function scopedUsers(Request $request)
    {
        return User::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->when($request->user()->agency_id, fn ($query, $agencyId) => $query->where('agency_id', $agencyId));
    }

    private function formData(Request $request, User $user): array
    {
        return [
            'managedUser' => $user,
            'agencies' => Agency::query()
                ->where('is_active', true)
                ->when($request->user()->agency_id, fn ($query, $agencyId) => $query->whereKey($agencyId))
                ->orderBy('name')->get(),
            'roles' => Role::query()
                ->whereNull('tenant_id')
                ->when($request->user()->isAgencyManager(), fn ($query) => $query->whereIn('slug', ['rental-agent', 'fleet-manager', 'viewer-auditor']))
                ->orderBy('name')->get(),
        ];
    }

    private function temporaryPasswordResponse(User $user, string $temporaryPassword, string $title): Response
    {
        return response()->view('shared.temporary-password', [
            'title' => $title,
            'message' => 'Transmettez ce mot de passe par un canal sûr. Il ne sera plus affiché et devra être changé à la première connexion.',
            'loginEmail' => $user->email,
            'temporaryPassword' => $temporaryPassword,
            'continueUrl' => route('users.index'),
        ])->header('Cache-Control', 'no-store, private');
    }
}
