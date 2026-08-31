<?php

namespace App\Http\Controllers;

use App\Actions\PlatformBilling\AssignSaasSubscription;
use App\Actions\PlatformBilling\TransitionSaasSubscription;
use App\Enums\PlatformBilling\TenantSubscriptionStatus;
use App\Http\Requests\PlatformBilling\StoreSaasSubscriptionRequest;
use App\Http\Requests\PlatformBilling\TransitionSaasSubscriptionRequest;
use App\Models\PlatformBilling\SaasPlan;
use App\Models\PlatformBilling\SaasSubscription;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlatformSubscriptionController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(TenantSubscriptionStatus::class)],
        ]);

        $subscriptions = SaasSubscription::query()
            ->with(['tenant:id,name,slug,status', 'plan:id,name,code'])
            ->when($filters['q'] ?? null, fn ($query, string $search) => $query
                ->whereHas('tenant', fn ($tenant) => $tenant
                    ->where('name', 'ilike', '%'.$search.'%')
                    ->orWhere('slug', 'ilike', '%'.$search.'%')))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('starts_at')
            ->paginate(20)
            ->withQueryString();

        return view('platform.subscriptions.index', [
            'subscriptions' => $subscriptions,
            'statuses' => TenantSubscriptionStatus::cases(),
        ]);
    }

    public function create(Tenant $tenant): View
    {
        $current = SaasSubscription::query()
            ->where('tenant_id', $tenant->getKey())
            ->whereIn('status', collect(TenantSubscriptionStatus::current())->pluck('value'))
            ->with('plan')
            ->first();

        return view('platform.subscriptions.create', [
            'tenant' => $tenant,
            'currentSubscription' => $current,
            'plans' => SaasPlan::query()->where('is_active', true)->orderBy('name')->get(),
            'initialStatuses' => [TenantSubscriptionStatus::Trialing, TenantSubscriptionStatus::Active],
        ]);
    }

    public function store(
        StoreSaasSubscriptionRequest $request,
        Tenant $tenant,
        AssignSaasSubscription $assign,
    ): RedirectResponse {
        $data = $request->validated();
        $plan = SaasPlan::query()->whereKey($data['saas_plan_id'])->where('is_active', true)->firstOrFail();
        unset($data['saas_plan_id']);
        $assign->handle($tenant, $plan, $data, $request->user()->getKey());

        return redirect()->route('platform.tenants.show', $tenant)
            ->with('status', 'Abonnement SaaS enregistré sans transaction bancaire.');
    }

    public function transition(
        TransitionSaasSubscriptionRequest $request,
        SaasSubscription $subscription,
        TransitionSaasSubscription $transition,
    ): RedirectResponse {
        $status = TenantSubscriptionStatus::from($request->validated('status'));
        $transition->handle($subscription, $status, $request->user()->getKey());

        return back()->with('status', 'État de l’abonnement SaaS mis à jour.');
    }
}
