<?php

namespace App\Http\Controllers;

use App\Actions\PlatformBilling\CreateSaasPlan;
use App\Actions\PlatformBilling\UpdateSaasPlan;
use App\Enums\PlatformBilling\SaasBillingInterval;
use App\Http\Requests\PlatformBilling\StoreSaasPlanRequest;
use App\Http\Requests\PlatformBilling\UpdateSaasPlanRequest;
use App\Models\PlatformBilling\SaasPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlatformPlanController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'in:0,1'],
        ]);

        $plans = SaasPlan::query()
            ->withCount('subscriptions')
            ->when($filters['q'] ?? null, fn ($query, string $search) => $query
                ->where(fn ($nested) => $nested
                    ->where('name', 'ilike', '%'.$search.'%')
                    ->orWhere('code', 'ilike', '%'.$search.'%')))
            ->when(array_key_exists('active', $filters), fn ($query) => $query
                ->where('is_active', (bool) ((int) $filters['active'])))
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('platform.plans.index', [
            'plans' => $plans,
            'intervals' => SaasBillingInterval::cases(),
        ]);
    }

    public function store(
        StoreSaasPlanRequest $request,
        CreateSaasPlan $create,
    ): RedirectResponse {
        $create->handle($request->validated(), $request->user()->getKey());

        return redirect()->route('platform.plans.index')
            ->with('status', 'Plan SaaS créé. Aucun paiement réel n’a été déclenché.');
    }

    public function update(
        UpdateSaasPlanRequest $request,
        SaasPlan $plan,
        UpdateSaasPlan $update,
    ): RedirectResponse {
        $updated = $update->handle($plan, $request->validated(), $request->user()->getKey());

        return redirect()->route('platform.plans.index')
            ->with('status', $updated->is_active ? 'Plan SaaS mis à jour.' : 'Plan SaaS désactivé.');
    }
}
