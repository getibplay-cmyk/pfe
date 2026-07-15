<?php

namespace App\Http\Controllers;

use App\Actions\Insurance\ApproveInsuranceClaim;
use App\Actions\Insurance\CloseInsuranceClaim;
use App\Actions\Insurance\CreateInsuranceClaim;
use App\Actions\Insurance\RejectInsuranceClaim;
use App\Actions\Insurance\SettleInsuranceClaim;
use App\Actions\Insurance\StartInsuranceClaimReview;
use App\Actions\Insurance\SubmitInsuranceClaim;
use App\Http\Requests\Insurance\StoreInsuranceCompanyRequest;
use App\Http\Requests\Insurance\StoreInsuranceCoverageRequest;
use App\Http\Requests\Insurance\StoreInsurancePolicyRequest;
use App\Http\Requests\InsuranceClaimTransitionRequest;
use App\Http\Requests\StoreInsuranceClaimRequest;
use App\Models\Agency;
use App\Models\DamageReport;
use App\Models\InsuranceClaim;
use App\Models\InsuranceCompany;
use App\Models\InsurancePolicy;
use App\Models\RentalContract;
use App\Models\Vehicle;
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
            'claims' => InsuranceClaim::with('policy')->when($agency, fn ($query) => $query->where('agency_id', $agency))->latest('reported_at')->limit(20)->get(),
        ]);
    }

    public function createPolicy(Request $request): View
    {
        $this->permit($request, 'insurance.manage');
        $agency = $request->user()->agency_id;

        return view('insurance.policies.create', [
            'agencies' => Agency::query()->when($agency, fn ($query) => $query->whereKey($agency))->orderBy('name')->get(),
            'vehicles' => Vehicle::query()->when($agency, fn ($query) => $query->where('agency_id', $agency))->orderBy('registration_number')->get(),
            'companies' => InsuranceCompany::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function showPolicy(Request $request, InsurancePolicy $policy): View
    {
        $this->permitPolicy($request, $policy, 'insurance.view');
        $policy->load(['vehicle', 'company', 'coverages', 'claims']);

        return view('insurance.policies.show', ['policy' => $policy]);
    }

    public function createClaim(Request $request): View
    {
        $this->permit($request, 'claim.manage');
        $agency = $request->user()->agency_id;
        $scope = fn ($query) => $query->when($agency, fn ($builder) => $builder->where('agency_id', $agency));

        return view('insurance.claims.create', [
            'agencies' => Agency::query()->when($agency, fn ($query) => $query->whereKey($agency))->orderBy('name')->get(),
            'policies' => $scope(InsurancePolicy::with('vehicle'))->latest()->get(),
            'contracts' => $scope(RentalContract::with(['vehicle', 'customer']))->latest()->limit(100)->get(),
            'damages' => $scope(DamageReport::with('rentalContract'))->latest()->limit(100)->get(),
        ]);
    }

    public function showClaim(Request $request, InsuranceClaim $claim): View
    {
        $this->permitClaim($request, $claim, 'claim.view');
        $claim->load(['policy.vehicle', 'damageReport', 'rentalContract', 'statusHistories.actor']);

        return view('insurance.claims.show', ['claim' => $claim]);
    }

    public function storeCompany(StoreInsuranceCompanyRequest $request): RedirectResponse
    {
        InsuranceCompany::create([...$request->validated(), 'is_active' => true]);

        return back()->with('status', 'Compagnie ajoutée.');
    }

    public function storePolicy(StoreInsurancePolicyRequest $request): RedirectResponse
    {
        $data = $request->validated();
        Vehicle::where('agency_id', $data['agency_id'])->findOrFail($data['vehicle_id']);
        InsuranceCompany::findOrFail($data['insurance_company_id']);
        $policy = new InsurancePolicy(collect($data)->except('policy_number')->all());
        $policy->setPolicyNumber($data['policy_number'])->save();

        return redirect()->route('insurance.policies.show', $policy)->with('status', 'Police enregistrée avec numéro chiffré.');
    }

    public function storeCoverage(StoreInsuranceCoverageRequest $request, InsurancePolicy $policy): RedirectResponse
    {
        $policy->coverages()->create($request->validated());

        return back()->with('status', 'Garantie ajoutée.');
    }

    public function storeClaim(StoreInsuranceClaimRequest $request, CreateInsuranceClaim $action): RedirectResponse
    {
        $claim = $action->handle($request->validated(), $request->user()->id);

        return redirect()->route('insurance.claims.show', $claim)->with('status', 'Sinistre enregistré sans décision automatique de responsabilité.');
    }

    public function submit(InsuranceClaimTransitionRequest $request, InsuranceClaim $claim, SubmitInsuranceClaim $action): RedirectResponse
    {
        $action->handle($claim, $request->user()->id, $request->validated('note'));

        return back()->with('status', 'Sinistre soumis pour instruction.');
    }

    public function review(InsuranceClaimTransitionRequest $request, InsuranceClaim $claim, StartInsuranceClaimReview $action): RedirectResponse
    {
        $action->handle($claim, $request->user()->id, $request->validated('note'));

        return back()->with('status', 'Revue humaine du sinistre démarrée.');
    }

    public function approve(InsuranceClaimTransitionRequest $request, InsuranceClaim $claim, ApproveInsuranceClaim $action): RedirectResponse
    {
        $action->handle($claim, (string) $request->validated('approved_amount'), $request->user()->id, $request->validated('note'));

        return back()->with('status', 'Sinistre approuvé par décision humaine.');
    }

    public function reject(InsuranceClaimTransitionRequest $request, InsuranceClaim $claim, RejectInsuranceClaim $action): RedirectResponse
    {
        $action->handle($claim, $request->user()->id, $request->validated('note'));

        return back()->with('status', 'Sinistre rejeté par décision humaine.');
    }

    public function settle(InsuranceClaimTransitionRequest $request, InsuranceClaim $claim, SettleInsuranceClaim $action): RedirectResponse
    {
        $action->handle($claim, (string) $request->validated('settled_amount'), $request->user()->id, $request->validated('note'));

        return back()->with('status', 'Règlement du sinistre enregistré.');
    }

    public function close(InsuranceClaimTransitionRequest $request, InsuranceClaim $claim, CloseInsuranceClaim $action): RedirectResponse
    {
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

    private function permitClaim(Request $request, InsuranceClaim $claim, string $permission = 'claim.manage'): void
    {
        $this->permit($request, $permission);
        abort_if($request->user()->agency_id && $request->user()->agency_id !== $claim->agency_id, 403);
    }
}
