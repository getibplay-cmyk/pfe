<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class TenantUserController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        return view('users.index', [
            'users' => $this->scopedUsers($request)->with(['agency', 'role'])->orderBy('name')->paginate(20),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', User::class);

        return view('users.form', $this->formData($request, new User));
    }

    public function store(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $this->authorize('create', User::class);
        $data = $this->validated($request);
        [$role, $agencyId] = $this->resolveAssignments($request, $data);

        $user = User::forceCreate([
            'tenant_id' => $request->user()->tenant_id,
            'agency_id' => $agencyId,
            'role_id' => $role->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_active' => $data['is_active'],
        ]);
        $audit->record('user.created', $user, [], $user->only(['name', 'email', 'agency_id', 'role_id', 'is_active']));

        return redirect()->route('users.index')->with('status', 'Utilisateur créé.');
    }

    public function edit(Request $request, int $user): View
    {
        $subject = $this->findScopedUser($request, $user);
        $this->authorize('update', $subject);

        return view('users.form', $this->formData($request, $subject));
    }

    public function update(Request $request, int $user, AuditRecorder $audit): RedirectResponse
    {
        $subject = $this->findScopedUser($request, $user);
        $this->authorize('update', $subject);
        $data = $this->validated($request, $subject);
        [$role, $agencyId] = $this->resolveAssignments($request, $data);
        $old = $subject->only(['name', 'email', 'agency_id', 'role_id', 'is_active']);

        $subject->forceFill([
            'agency_id' => $agencyId,
            'role_id' => $role->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'is_active' => $data['is_active'],
        ]);
        if (! empty($data['password'])) {
            $subject->password = Hash::make($data['password']);
        }
        $subject->save();
        $audit->record('user.updated', $subject, $old, $subject->only(array_keys($old)));

        return redirect()->route('users.index')->with('status', 'Utilisateur mis à jour.');
    }

    private function scopedUsers(Request $request)
    {
        return User::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->when($request->user()->isAgencyManager(), fn ($query) => $query->where('agency_id', $request->user()->agency_id));
    }

    private function findScopedUser(Request $request, int $id): User
    {
        return $this->scopedUsers($request)->findOrFail($id);
    }

    private function formData(Request $request, User $user): array
    {
        return [
            'managedUser' => $user,
            'agencies' => Agency::query()
                ->when($request->user()->isAgencyManager(), fn ($query) => $query->whereKey($request->user()->agency_id))
                ->orderBy('name')->get(),
            'roles' => Role::whereNull('tenant_id')
                ->when($request->user()->isAgencyManager(), fn ($query) => $query->whereNot('slug', 'tenant-owner'))
                ->orderBy('name')->get(),
        ];
    }

    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'tenant_id' => ['prohibited'],
            'is_platform_admin' => ['prohibited'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'password' => [$user ? 'nullable' : 'required', 'string', Password::defaults()],
            'role_id' => ['required', 'integer'],
            'agency_id' => ['nullable', 'integer'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    private function resolveAssignments(Request $request, array $data): array
    {
        $role = Role::query()
            ->where('id', $data['role_id'])
            ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $request->user()->tenant_id))
            ->firstOrFail();
        abort_if($request->user()->isAgencyManager() && $role->slug === 'tenant-owner', 403);

        $agencyId = $request->user()->isAgencyManager() ? $request->user()->agency_id : ($data['agency_id'] ?? null);
        if ($role->slug !== 'tenant-owner') {
            abort_unless($agencyId && Agency::whereKey($agencyId)->exists(), 422, 'Une agence du tenant est obligatoire.');
        } else {
            $agencyId = null;
        }

        return [$role, $agencyId];
    }
}
