<?php

namespace App\Http\Controllers;

use App\Actions\Platform\ProvisionTenant;
use App\Actions\Platform\ReactivateTenant;
use App\Actions\Platform\SuspendTenant;
use App\Enums\TenantStatus;
use App\Http\Requests\Platform\StoreTenantRequest;
use App\Http\Requests\Platform\SuspendTenantRequest;
use App\Http\Requests\Platform\UpdateTenantRequest;
use App\Models\Agency;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PlatformTenantController extends Controller
{
    public function index(): View
    {
        $query = Tenant::query()
            ->when(request('q'), fn ($builder, $search) => $builder->where(fn ($nested) => $nested
                ->where('name', 'ilike', '%'.$search.'%')
                ->orWhere('slug', 'ilike', '%'.$search.'%')
                ->orWhere('legal_name', 'ilike', '%'.$search.'%')))
            ->when(request('status'), fn ($builder, $status) => $builder->where('status', $status))
            ->latest();

        return view('platform.tenants.index', [
            'tenants' => $query->paginate(20)->withQueryString(),
            'statuses' => TenantStatus::cases(),
        ]);
    }

    public function create(): View
    {
        return view('platform.tenants.form', ['tenant' => new Tenant]);
    }

    public function store(StoreTenantRequest $request, ProvisionTenant $action): Response
    {
        $result = $action->handle($request->validated(), $request->user()->id);

        return response()->view('shared.temporary-password', [
            'title' => 'Tenant provisionné',
            'message' => 'Transmettez ces identifiants au propriétaire par un canal sûr. Le mot de passe ne sera plus affiché.',
            'loginEmail' => $request->validated('owner_email'),
            'temporaryPassword' => $result['temporary_password'],
            'continueUrl' => route('platform.tenants.show', $result['tenant']),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function show(Tenant $tenant): View
    {
        $ownerRoleId = DB::table('roles')->where('slug', 'tenant-owner')->whereNull('tenant_id')->value('id');

        return view('platform.tenants.show', [
            'tenant' => $tenant,
            'agencies' => Agency::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'owner' => User::query()->where('tenant_id', $tenant->id)->where('role_id', $ownerRoleId)->where('is_active', true)->first(),
            'counts' => [
                'Agences' => DB::table('agencies')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->count(),
                'Utilisateurs actifs' => DB::table('users')->where('tenant_id', $tenant->id)->where('is_active', true)->count(),
                'Véhicules' => DB::table('vehicles')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->count(),
                'Réservations' => DB::table('reservations')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->count(),
                'Contrats' => DB::table('rental_contracts')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->count(),
            ],
        ]);
    }

    public function edit(Tenant $tenant): View
    {
        return view('platform.tenants.form', compact('tenant'));
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validated();
        $old = $tenant->only(['name', 'slug', 'legal_name', 'email', 'phone', 'settings']);
        $tenant->update([
            ...collect($data)->only(['name', 'slug', 'legal_name', 'email', 'phone'])->all(),
            'settings' => [
                ...($tenant->settings ?? []),
                'address' => $data['address'] ?? null,
                'currency' => $data['currency'],
                'timezone' => $data['timezone'],
            ],
        ]);
        $audit->record('platform.tenant.updated', $tenant, $old, $tenant->only(array_keys($old)));

        return redirect()->route('platform.tenants.show', $tenant)->with('status', 'Tenant mis à jour.');
    }

    public function suspend(SuspendTenantRequest $request, Tenant $tenant, SuspendTenant $action): RedirectResponse
    {
        $action->handle($tenant, $request->validated('reason'), $request->user()->id);

        return back()->with('status', 'Tenant suspendu et sessions révoquées.');
    }

    public function reactivate(Tenant $tenant, ReactivateTenant $action): RedirectResponse
    {
        $action->handle($tenant);

        return back()->with('status', 'Tenant réactivé.');
    }
}
