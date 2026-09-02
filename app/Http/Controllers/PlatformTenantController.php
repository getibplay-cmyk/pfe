<?php

namespace App\Http\Controllers;

use App\Actions\Platform\ProvisionTenant;
use App\Actions\Platform\ReactivateTenant;
use App\Actions\Platform\SuspendTenant;
use App\Enums\IntelligenceCapability;
use App\Enums\TenantStatus;
use App\Http\Requests\Platform\StoreTenantRequest;
use App\Http\Requests\Platform\SuspendTenantRequest;
use App\Http\Requests\Platform\UpdateTenantRequest;
use App\Models\Agency;
use App\Models\PlatformBilling\SaasPayment;
use App\Models\PlatformBilling\SaasPlan;
use App\Models\PlatformBilling\SaasSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\VerificationNotificationSender;
use App\Support\Intelligence\IntelligenceCapabilityCatalog;
use App\Support\Intelligence\TenantIntelligenceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlatformTenantController extends Controller
{
    private const CURRENT_SUBSCRIPTION_STATUSES = ['trialing', 'active', 'past_due', 'suspended'];

    public function index(): View
    {
        $filters = request()->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(TenantStatus::class)],
        ]);
        $query = Tenant::query()
            ->withCount(['agencies', 'users'])
            ->selectSub(
                DB::table('vehicles')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('vehicles.tenant_id', 'tenants.id')
                    ->whereNull('vehicles.deleted_at'),
                'vehicles_count',
            )
            ->selectSub(
                DB::table('tenant_intelligence_accesses')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('tenant_intelligence_accesses.tenant_id', 'tenants.id')
                    ->where('enabled', true),
                'enabled_capabilities_count',
            )
            ->selectSub(
                DB::table('saas_subscriptions')
                    ->select('status')
                    ->whereColumn('saas_subscriptions.tenant_id', 'tenants.id')
                    ->whereIn('status', self::CURRENT_SUBSCRIPTION_STATUSES)
                    ->latest('starts_at')
                    ->limit(1),
                'current_subscription_status',
            )
            ->selectSub(
                DB::table('saas_subscriptions')
                    ->join('saas_plans', 'saas_plans.id', '=', 'saas_subscriptions.saas_plan_id')
                    ->select('saas_plans.name')
                    ->whereColumn('saas_subscriptions.tenant_id', 'tenants.id')
                    ->whereIn('saas_subscriptions.status', self::CURRENT_SUBSCRIPTION_STATUSES)
                    ->latest('saas_subscriptions.starts_at')
                    ->limit(1),
                'current_plan_name',
            )
            ->when($filters['q'] ?? null, fn ($builder, $search) => $builder->where(fn ($nested) => $nested
                ->where('name', 'ilike', '%'.$search.'%')
                ->orWhere('slug', 'ilike', '%'.$search.'%')
                ->orWhere('legal_name', 'ilike', '%'.$search.'%')))
            ->when($filters['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status))
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

    public function store(
        StoreTenantRequest $request,
        ProvisionTenant $action,
        VerificationNotificationSender $verificationSender,
    ): Response
    {
        $result = $action->handle($request->validated(), $request->user()->id);
        $verificationSent = $verificationSender->send($result['owner']);

        return response()->view('shared.temporary-password', [
            'title' => 'Entreprise cliente créée',
            'message' => $verificationSent
                ? 'Le lien de vérification a été envoyé. Transmettez le mot de passe temporaire par un canal distinct et sûr.'
                : 'Le compte est créé, mais le lien de vérification n’a pas pu être envoyé. Vérifiez la configuration e-mail puis demandez un nouvel envoi.',
            'loginEmail' => $request->validated('owner_email'),
            'temporaryPassword' => $result['temporary_password'],
            'continueUrl' => route('platform.tenants.show', $result['tenant']),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function show(
        Tenant $tenant,
        IntelligenceCapabilityCatalog $catalog,
        TenantIntelligenceAccess $intelligenceAccess,
    ): View {
        $ownerRoleId = DB::table('roles')->where('slug', 'tenant-owner')->whereNull('tenant_id')->value('id');
        $currentSubscription = SaasSubscription::query()
            ->with('plan')
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', self::CURRENT_SUBSCRIPTION_STATUSES)
            ->latest('starts_at')
            ->first();
        $payments = SaasPayment::query()
            ->where('tenant_id', $tenant->id)
            ->latest('occurred_at')
            ->limit(20)
            ->get();
        $activationRows = DB::table('tenant_intelligence_accesses')
            ->leftJoin('users', 'users.id', '=', 'tenant_intelligence_accesses.updated_by')
            ->where('tenant_intelligence_accesses.tenant_id', $tenant->id)
            ->get([
                'tenant_intelligence_accesses.capability',
                'tenant_intelligence_accesses.enabled',
                'tenant_intelligence_accesses.changed_at',
                'users.name as updated_by_name',
            ])
            ->keyBy('capability');
        $capabilities = collect(IntelligenceCapability::cases())->map(function (IntelligenceCapability $capability) use ($activationRows, $catalog, $intelligenceAccess): array {
            $access = $activationRows->get($capability->value);
            $availability = $intelligenceAccess->status($capability, (int) $tenant->id);

            return [
                'key' => $capability->value,
                'label' => $catalog->definition($capability)['label'],
                'enabled' => (bool) ($access?->enabled ?? false),
                'available' => $availability->globallyEnabled && $availability->runtimeReady,
                'usable' => $availability->usable(),
                'message' => $availability->message,
                'changed_at' => $access?->changed_at
                    ? CarbonImmutable::parse((string) $access->changed_at)
                    : null,
                'updated_by_name' => $access?->updated_by_name,
            ];
        })->values();
        $administrativeHistory = DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->where('audit_logs.tenant_id', $tenant->id)
            ->where('audit_logs.action', 'like', 'platform.%')
            ->latest('audit_logs.created_at')
            ->limit(30)
            ->get(['audit_logs.action', 'audit_logs.created_at', 'users.name as actor_name']);

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
            'currentSubscription' => $currentSubscription,
            'hasActivePlans' => SaasPlan::query()->where('is_active', true)->exists(),
            'saasPayments' => $payments,
            'capabilities' => $capabilities,
            'administrativeHistory' => $administrativeHistory,
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

        return redirect()->route('platform.tenants.show', $tenant)->with('status', 'Entreprise cliente mise à jour.');
    }

    public function suspend(SuspendTenantRequest $request, Tenant $tenant, SuspendTenant $action): RedirectResponse
    {
        $action->handle($tenant, $request->validated('reason'), $request->user()->id);

        return back()->with('status', 'Entreprise cliente suspendue et sessions révoquées.');
    }

    public function reactivate(Tenant $tenant, ReactivateTenant $action): RedirectResponse
    {
        $action->handle($tenant);

        return back()->with('status', 'Entreprise cliente réactivée.');
    }
}
