<?php

namespace App\Http\Controllers;

use App\Actions\Pricing\CreatePricingRule;
use App\Actions\Pricing\VersionPricingRule;
use App\Models\Agency;
use App\Models\PricingRule;
use App\Models\VehicleCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PricingRuleController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', PricingRule::class);
        $rules = PricingRule::with(['agency', 'vehicleCategory'])
            ->when($request->user()->agency_id, fn ($query, $agencyId) => $query->where(fn ($scope) => $scope->whereNull('agency_id')->orWhere('agency_id', $agencyId)))
            ->when($request->integer('agency_id'), fn ($query, $agencyId) => $query->where('agency_id', $agencyId))
            ->when($request->integer('category_id'), fn ($query, $categoryId) => $query->where('vehicle_category_id', $categoryId))
            ->orderByDesc('is_active')->orderByDesc('valid_from')->paginate(20)->withQueryString();

        return view('pricing-rules.index', [...$this->formOptions($request), 'rules' => $rules]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', PricingRule::class);

        return view('pricing-rules.form', [...$this->formOptions($request), 'pricingRule' => new PricingRule]);
    }

    public function store(Request $request, CreatePricingRule $action): RedirectResponse
    {
        $this->authorize('create', PricingRule::class);
        $rule = $action->handle($this->validated($request), $request->user()->id);

        return redirect()->route('pricing-rules.index')->with('status', "Règle tarifaire {$rule->name} créée.");
    }

    public function edit(Request $request, PricingRule $pricingRule): View
    {
        $this->authorize('update', $pricingRule);

        return view('pricing-rules.form', [...$this->formOptions($request), 'pricingRule' => $pricingRule]);
    }

    public function update(Request $request, PricingRule $pricingRule, VersionPricingRule $action): RedirectResponse
    {
        $this->authorize('update', $pricingRule);
        $version = $action->handle($pricingRule, $this->validated($request), $request->user()->id);

        return redirect()->route('pricing-rules.index')->with('status', "Nouvelle version tarifaire #{$version->id} créée; l’ancienne est conservée.");
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'tenant_id' => ['prohibited'],
            'agency_id' => ['nullable', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'vehicle_category_id' => ['required', 'integer', Rule::exists('vehicle_categories', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'name' => ['required', 'string', 'max:255'],
            'daily_rate' => ['required', 'decimal:0,2', 'min:0'],
            'deposit_amount' => ['required', 'decimal:0,2', 'min:0'],
            'included_km_per_day' => ['nullable', 'integer', 'min:0'],
            'extra_km_rate' => ['nullable', 'decimal:0,2', 'min:0'],
            'late_hour_rate' => ['nullable', 'decimal:0,2', 'min:0'],
            'minimum_days' => ['required', 'integer', 'min:1'],
            'maximum_days' => ['nullable', 'integer', 'gte:minimum_days'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'priority' => ['required', 'integer'],
            'currency' => ['required', Rule::in(['MAD'])],
            'is_active' => ['required', 'boolean'],
        ]);
        abort_if($request->user()->agency_id !== null && $request->user()->agency_id !== (int) ($data['agency_id'] ?? 0), 403);

        return $data;
    }

    private function formOptions(Request $request): array
    {
        $agencies = Agency::query()->when($request->user()->agency_id, fn ($query, $id) => $query->whereKey($id))->orderBy('name')->get();

        return ['agencies' => $agencies, 'categories' => VehicleCategory::where('is_active', true)->orderBy('name')->get()];
    }
}
