<?php

namespace App\Http\Controllers;

use App\Actions\PlatformBilling\RecordSaasPayment;
use App\Actions\PlatformBilling\ReverseSaasPayment;
use App\Enums\PlatformBilling\SaasPaymentEntryType;
use App\Enums\PlatformBilling\SaasPaymentMethod;
use App\Enums\PlatformBilling\TenantSubscriptionStatus;
use App\Http\Requests\PlatformBilling\ReverseSaasPaymentRequest;
use App\Http\Requests\PlatformBilling\StoreSaasPaymentRequest;
use App\Models\PlatformBilling\SaasPayment;
use App\Models\PlatformBilling\SaasSubscription;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlatformSaasPaymentController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'entry_type' => ['nullable', Rule::enum(SaasPaymentEntryType::class)],
        ]);

        $payments = SaasPayment::query()
            ->with(['tenant:id,name,slug', 'subscription.plan:id,name,code', 'reversal:id,reversal_of_id'])
            ->when($filters['q'] ?? null, fn ($query, string $search) => $query->where(
                fn ($searchQuery) => $searchQuery
                    ->whereHas('tenant', fn ($tenant) => $tenant
                        ->where('name', 'ilike', '%'.$search.'%')
                        ->orWhere('slug', 'ilike', '%'.$search.'%'))
                    ->orWhere('reference', 'ilike', '%'.$search.'%'),
            ))
            ->when($filters['entry_type'] ?? null, fn ($query, string $type) => $query->where('entry_type', $type))
            ->latest('occurred_at')
            ->paginate(20)
            ->withQueryString();

        return view('platform.saas-payments.index', [
            'payments' => $payments,
            'entryTypes' => SaasPaymentEntryType::cases(),
        ]);
    }

    public function create(Tenant $tenant): View
    {
        $subscription = SaasSubscription::query()
            ->with('plan')
            ->where('tenant_id', $tenant->getKey())
            ->whereIn('status', collect(TenantSubscriptionStatus::current())->pluck('value'))
            ->latest('starts_at')
            ->first();

        return view('platform.saas-payments.create', [
            'tenant' => $tenant,
            'subscription' => $subscription,
            'methods' => SaasPaymentMethod::cases(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(
        StoreSaasPaymentRequest $request,
        Tenant $tenant,
        SaasSubscription $subscription,
        RecordSaasPayment $record,
    ): RedirectResponse {
        abort_unless($subscription->tenant_id === $tenant->getKey(), 404);
        $record->handle($subscription, $request->validated(), $request->user()->getKey());

        return redirect()->route('platform.saas-payments.index')
            ->with('status', 'Paiement SaaS manuel enregistré. Aucune passerelle bancaire n’a été appelée.');
    }

    public function reverse(
        ReverseSaasPaymentRequest $request,
        SaasPayment $payment,
        ReverseSaasPayment $reverse,
    ): RedirectResponse {
        $reverse->handle($payment, $request->validated(), $request->user()->getKey());

        return back()->with('status', 'Contrepassation SaaS enregistrée sans modifier le paiement original.');
    }
}
