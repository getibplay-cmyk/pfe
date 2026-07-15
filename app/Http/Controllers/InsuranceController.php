<?php

namespace App\Http\Controllers;

use App\Actions\Insurance\ApproveInsuranceClaim;
use App\Actions\Insurance\CloseInsuranceClaim;
use App\Actions\Insurance\CreateInsuranceClaim;
use App\Actions\Insurance\RejectInsuranceClaim;
use App\Actions\Insurance\SettleInsuranceClaim;
use App\Actions\Insurance\StartInsuranceClaimReview;
use App\Actions\Insurance\SubmitInsuranceClaim;
use App\Http\Requests\InsuranceClaimTransitionRequest;
use App\Http\Requests\StoreInsuranceClaimRequest;
use App\Models\InsuranceClaim;
use App\Models\InsuranceCompany;
use App\Models\InsurancePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'claims' => InsuranceClaim::with(['policy', 'statusHistories.actor'])->when($agency, fn ($query) => $query->where('agency_id', $agency))->latest('reported_at')->limit(20)->get(),
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

    public function storeClaim(StoreInsuranceClaimRequest $request, CreateInsuranceClaim $action): RedirectResponse
    {
        $this->permit($request, 'claim.manage');
        $action->handle($request->validated(), $request->user()->id);

        return back()->with('status', 'Sinistre enregistré sans décision automatique de responsabilité.');
    }

    public function submit(InsuranceClaimTransitionRequest $request, InsuranceClaim $claim, SubmitInsuranceClaim $action): RedirectResponse
    {
        $this->permitClaim($request, $claim);
        $action->handle($claim, $request->user()->id, $request->validated('note'));

        return back()->with('status', 'Sinistre soumis pour instruction.');
    }

    public function review(InsuranceClaimTransitionRequest $request, InsuranceClaim $claim, StartInsuranceClaimReview $action): RedirectResponse
    {
        $this->permitClaim($request, $claim);
        $action->handle($claim, $request->user()->id, $request->validated('note'));

        return back()->with('status', 'Revue humaine du sinistre démarrée.');
    }

    public function approve(InsuranceClaimTransitionRequest $request, InsuranceClaim $claim, ApproveInsuranceClaim $action): RedirectResponse
    {
        $this->permitClaim($request, $claim);
        $action->handle($claim, (string) $request->validated('approved_amount'), $request->user()->id, $request->validated('note'));

        return back()->with('status', 'Sinistre approuvé par décision humaine.');
    }

    public function reject(InsuranceClaimTransitionRequest $request, InsuranceClaim $claim, RejectInsuranceClaim $action): RedirectResponse
    {
        $this->permitClaim($request, $claim);
        $action->handle($claim, $request->user()->id, $request->validated('note'));

        return back()->with('status', 'Sinistre rejeté par décision humaine.');
    }

    public function settle(InsuranceClaimTransitionRequest $request, InsuranceClaim $claim, SettleInsuranceClaim $action): RedirectResponse
    {
        $this->permitClaim($request, $claim);
        $action->handle($claim, (string) $request->validated('settled_amount'), $request->user()->id, $request->validated('note'));

        return back()->with('status', 'Règlement du sinistre enregistré.');
    }

    public function close(InsuranceClaimTransitionRequest $request, InsuranceClaim $claim, CloseInsuranceClaim $action): RedirectResponse
    {
        $this->permitClaim($request, $claim);
        $action->handle($claim, $request->user()->id, $request->validated('note'));

        return back()->with('status', 'Sinistre clôturé.');
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

    private function permitClaim(Request $request, InsuranceClaim $claim): void
    {
        $this->permit($request, 'claim.manage');
        abort_if($request->user()->agency_id && $request->user()->agency_id !== $claim->agency_id, 403);
    }
}
