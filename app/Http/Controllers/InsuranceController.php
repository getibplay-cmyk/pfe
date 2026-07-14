<?php

namespace App\Http\Controllers;

use App\Actions\Rentals\GenerateBusinessNumber;
use App\Models\InsuranceClaim;
use App\Models\InsuranceCompany;
use App\Models\InsurancePolicy;
use App\Support\Pricing\DecimalMoney;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InsuranceController extends Controller
{
    public function index(Request $request): View
    {
        $this->permit($request, 'insurance.view');
        $agency = $request->user()->agency_id;

        return view('insurance.index', [
            'companies' => InsuranceCompany::orderBy('name')->get(),
            'policies' => InsurancePolicy::with(['vehicle', 'company', 'coverages'])->when($agency, fn ($query) => $query->where('agency_id', $agency))->latest()->paginate(20),
            'claims' => InsuranceClaim::with('policy')->when($agency, fn ($query) => $query->where('agency_id', $agency))->latest('reported_at')->limit(20)->get(),
        ]);
    }

    public function storeCompany(Request $request): RedirectResponse
    {
        $this->permit($request, 'insurance.manage');
        $data = $request->validate(['tenant_id' => ['prohibited'], 'name' => ['required', 'string', 'max:255'], 'email' => ['nullable', 'email'], 'phone' => ['nullable', 'string', 'max:50']]);
        InsuranceCompany::create([...$data, 'is_active' => true]);

        return back()->with('status', 'Compagnie ajoutée.');
    }

    public function storePolicy(Request $request): RedirectResponse
    {
        $this->permit($request, 'insurance.manage');
        $data = $request->validate(['tenant_id' => ['prohibited'], 'agency_id' => ['required', 'integer'], 'vehicle_id' => ['required', 'integer'], 'insurance_company_id' => ['required', 'integer'], 'policy_number' => ['required', 'string', 'max:255'], 'policy_type' => ['required', 'in:mandatory_liability,comprehensive,third_party,other'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after_or_equal:starts_at'], 'premium_amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'], 'deductible_amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'], 'currency' => ['nullable', 'size:3'], 'status' => ['required', 'in:draft,active,expired,cancelled']]);
        abort_if($request->user()->agency_id && $request->user()->agency_id !== (int) $data['agency_id'], 403);
        $policy = new InsurancePolicy(collect($data)->except('policy_number')->all());
        $policy->setPolicyNumber($data['policy_number'])->save();

        return back()->with('status', 'Police enregistrée avec numéro chiffré.');
    }

    public function storeCoverage(Request $request, InsurancePolicy $policy): RedirectResponse
    {
        $this->permitPolicy($request, $policy, 'insurance.manage');
        $data = $request->validate(['coverage_type' => ['required', 'in:liability,collision,theft,fire,glass,assistance,legal_defence,other'], 'label' => ['required', 'string', 'max:255'], 'limit_amount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'], 'deductible_amount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'], 'terms' => ['nullable', 'array']]);
        $policy->coverages()->create($data);

        return back()->with('status', 'Garantie ajoutée.');
    }

    public function storeClaim(Request $request, GenerateBusinessNumber $numbers): RedirectResponse
    {
        $this->permit($request, 'claim.manage');
        $data = $request->validate(['tenant_id' => ['prohibited'], 'agency_id' => ['required', 'integer'], 'insurance_policy_id' => ['required', 'integer'], 'damage_report_id' => ['nullable', 'integer'], 'rental_contract_id' => ['nullable', 'integer'], 'status' => ['required', 'in:reported,submitted,under_review,approved,rejected,settled,closed'], 'reported_at' => ['required', 'date'], 'claimed_amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'], 'approved_amount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'], 'settled_amount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'], 'insurer_reference' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string']]);
        abort_if($request->user()->agency_id && $request->user()->agency_id !== (int) $data['agency_id'], 403);
        $policy = InsurancePolicy::findOrFail($data['insurance_policy_id']);
        if ($policy->agency_id !== (int) $data['agency_id']) {
            throw ValidationException::withMessages(['insurance_policy_id' => 'Police incompatible avec cette agence.']);
        }
        foreach (['claimed_amount', 'approved_amount', 'settled_amount'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = DecimalMoney::fromMinorUnits(DecimalMoney::toMinorUnits($data[$field]));
            }
        }
        $data['insurer_reference_encrypted'] = $data['insurer_reference'] ?? null;
        unset($data['insurer_reference']);
        InsuranceClaim::create([...$data, 'claim_number' => $numbers->handle('claim'), 'created_by' => $request->user()->id]);

        return back()->with('status', 'Sinistre enregistré sans décision automatique de responsabilité.');
    }

    private function permit(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), 403);
    }

    private function permitPolicy(Request $request, InsurancePolicy $policy, string $permission): void
    {
        $this->permit($request, $permission);
        abort_if($request->user()->agency_id && $request->user()->agency_id !== $policy->agency_id, 403);
    }
}
