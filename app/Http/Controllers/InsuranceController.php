<?php

namespace App\Http\Controllers;

use App\Actions\Insurance\ActivateInsurancePolicy;
use App\Actions\Insurance\ApproveInsuranceClaim;
use App\Actions\Insurance\ArchiveInsuranceCoverage;
use App\Actions\Insurance\CancelInsurancePolicy;
use App\Actions\Insurance\CloseInsuranceClaim;
use App\Actions\Insurance\CreateInsuranceClaim;
use App\Actions\Insurance\CreateInsuranceCompany;
use App\Actions\Insurance\CreateInsuranceCoverage;
use App\Actions\Insurance\CreateInsurancePolicy;
use App\Actions\Insurance\DeactivateInsuranceCompany;
use App\Actions\Insurance\ReactivateInsuranceCompany;
use App\Actions\Insurance\RejectInsuranceClaim;
use App\Actions\Insurance\RenewInsurancePolicy;
use App\Actions\Insurance\SettleInsuranceClaim;
use App\Actions\Insurance\StartInsuranceClaimReview;
use App\Actions\Insurance\SubmitInsuranceClaim;
use App\Actions\Insurance\UpdateDraftInsurancePolicy;
use App\Actions\Insurance\UpdateInsuranceCompany;
use App\Actions\Insurance\UpdateInsuranceCoverage;
use App\Enums\DocumentType;
use App\Enums\InsuranceClaimStatus;
use App\Enums\InsurancePolicyStatus;
use App\Http\Requests\Insurance\CancelInsurancePolicyRequest;
use App\Http\Requests\Insurance\RenewInsurancePolicyRequest;
use App\Http\Requests\Insurance\StoreInsuranceCompanyRequest;
use App\Http\Requests\Insurance\StoreInsuranceCoverageRequest;
use App\Http\Requests\Insurance\StoreInsurancePolicyRequest;
use App\Http\Requests\Insurance\UpdateInsurancePolicyRequest;
use App\Http\Requests\InsuranceClaimTransitionRequest;
use App\Http\Requests\StoreInsuranceClaimRequest;
use App\Models\Agency;
use App\Models\DamageReport;
use App\Models\InsuranceClaim;
use App\Models\InsuranceCompany;
use App\Models\InsurancePolicy;
use App\Models\InsurancePolicyCoverage;
use App\Models\RentalContract;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InsuranceController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', InsurancePolicy::class);
        $agencyId = $request->user()->agency_id;
        $scope = fn (Builder $query): Builder => $query->when($agencyId, fn (Builder $builder) => $builder->where('agency_id', $agencyId));

        return view('insurance.index', [
            'companies' => InsuranceCompany::withCount(['policies' => fn (Builder $query) => $query->when($agencyId, fn (Builder $builder) => $builder->where('agency_id', $agencyId))])
                ->when($request->string('company_q')->isNotEmpty(), fn (Builder $query) => $query->where('name', 'ilike', '%'.$request->string('company_q').'%'))
                ->orderByDesc('is_active')->orderBy('name')->paginate(12, ['*'], 'companies_page')->withQueryString(),
            'policies' => $scope(InsurancePolicy::with(['vehicle:id,registration_number', 'company:id,name']))
                ->when($request->string('q')->isNotEmpty(), fn (Builder $query) => $query->where(fn (Builder $search) => $search->whereHas('vehicle', fn (Builder $vehicle) => $vehicle->where('registration_number', 'ilike', '%'.$request->string('q').'%'))->orWhereHas('company', fn (Builder $company) => $company->where('name', 'ilike', '%'.$request->string('q').'%'))))
                ->when($request->string('status')->isNotEmpty(), fn (Builder $query) => $query->where('status', $request->string('status')))
                ->when($request->string('policy_type')->isNotEmpty(), fn (Builder $query) => $query->where('policy_type', $request->string('policy_type')))
                ->when($request->integer('company_id') > 0, fn (Builder $query) => $query->where('insurance_company_id', $request->integer('company_id')))
                ->latest()->paginate(20)->withQueryString(),
            'claims' => $scope(InsuranceClaim::with('policy:id,vehicle_id,policy_number_encrypted,policy_number_hash'))
                ->when($request->string('claim_q')->isNotEmpty(), fn (Builder $query) => $query->where('claim_number', 'ilike', '%'.$request->string('claim_q').'%'))
                ->when($request->string('claim_status')->isNotEmpty(), fn (Builder $query) => $query->where('status', $request->string('claim_status')))
                ->latest('reported_at')->paginate(20, ['*'], 'claims_page')->withQueryString(),
            'statuses' => InsurancePolicyStatus::cases(),
            'claimStatuses' => InsuranceClaimStatus::cases(),
        ]);
    }

    public function storeCompany(StoreInsuranceCompanyRequest $request, CreateInsuranceCompany $action): RedirectResponse
    {
        $this->authorize('create', InsuranceCompany::class);
        $company = $action->handle($request->validated());

        return redirect()->route('insurance.companies.show', $company)->with('status', 'Compagnie créée.');
    }

    public function showCompany(Request $request, InsuranceCompany $company): View
    {
        $this->authorize('view', $company);
        $agencyId = $request->user()->agency_id;
        $company->loadCount(['policies' => fn (Builder $query) => $query->when($agencyId, fn (Builder $builder) => $builder->where('agency_id', $agencyId))]);

        return view('insurance.companies.show', ['company' => $company, 'policies' => $company->policies()->with('vehicle:id,registration_number')->when($agencyId, fn (Builder $query) => $query->where('agency_id', $agencyId))->latest()->paginate(20)]);
    }

    public function editCompany(InsuranceCompany $company): View
    {
        $this->authorize('update', $company);

        return view('insurance.companies.edit', compact('company'));
    }

    public function updateCompany(StoreInsuranceCompanyRequest $request, InsuranceCompany $company, UpdateInsuranceCompany $action): RedirectResponse
    {
        $this->authorize('update', $company);
        $action->handle($company, $request->validated());

        return redirect()->route('insurance.companies.show', $company)->with('status', 'Compagnie modifiée.');
    }

    public function deactivateCompany(Request $request, InsuranceCompany $company, DeactivateInsuranceCompany $action): RedirectResponse
    {
        $this->authorize('changeState', $company);
        $action->handle($company, $request->user()->id);

        return back()->with('status', 'Compagnie désactivée.');
    }

    public function reactivateCompany(Request $request, InsuranceCompany $company, ReactivateInsuranceCompany $action): RedirectResponse
    {
        $this->authorize('changeState', $company);
        $action->handle($company);

        return back()->with('status', 'Compagnie réactivée.');
    }

    public function createPolicy(Request $request): View
    {
        $this->authorize('create', InsurancePolicy::class);

        return view('insurance.policies.create', $this->policyFormData($request));
    }

    public function storePolicy(StoreInsurancePolicyRequest $request, CreateInsurancePolicy $action): RedirectResponse
    {
        $policy = $action->handle($request->validated(), $request->user()->id);

        return redirect()->route('insurance.policies.show', $policy)->with('status', 'Police créée en brouillon avec numéro chiffré.');
    }

    public function showPolicy(Request $request, InsurancePolicy $policy): View
    {
        $this->authorize('view', $policy);
        $policy->load(['agency:id,name', 'vehicle', 'company', 'coverages', 'claims', 'documents.currentVersion', 'statusHistories.actor', 'renewedFrom', 'renewals']);

        return view('insurance.policies.show', ['policy' => $policy, 'documentTypes' => DocumentType::insurancePolicyTypes()]);
    }

    public function editPolicy(Request $request, InsurancePolicy $policy): View
    {
        $this->authorize('update', $policy);

        return view('insurance.policies.edit', [...$this->policyFormData($request, $policy->agency_id), 'policy' => $policy]);
    }

    public function updatePolicy(UpdateInsurancePolicyRequest $request, InsurancePolicy $policy, UpdateDraftInsurancePolicy $action): RedirectResponse
    {
        $action->handle($policy, $request->validated());

        return redirect()->route('insurance.policies.show', $policy)->with('status', 'Police brouillon modifiée.');
    }

    public function activatePolicy(Request $request, InsurancePolicy $policy, ActivateInsurancePolicy $action): RedirectResponse
    {
        $this->authorize('activate', $policy);
        $action->handle($policy, $request->user()->id);

        return back()->with('status', 'Police activée après validation des garanties et du document privé.');
    }

    public function cancelPolicy(CancelInsurancePolicyRequest $request, InsurancePolicy $policy, CancelInsurancePolicy $action): RedirectResponse
    {
        $action->handle($policy, $request->validated('reason'), $request->user()->id);

        return back()->with('status', 'Police annulée.');
    }

    public function createRenewal(InsurancePolicy $policy): View
    {
        $this->authorize('renew', $policy);

        return view('insurance.policies.renew', compact('policy'));
    }

    public function renewPolicy(RenewInsurancePolicyRequest $request, InsurancePolicy $policy, RenewInsurancePolicy $action): RedirectResponse
    {
        $renewal = $action->handle($policy, [...$request->validated(), 'copy_coverages' => $request->boolean('copy_coverages')], $request->user()->id);

        return redirect()->route('insurance.policies.show', $renewal)->with('status', 'Renouvellement créé en brouillon ; aucun fichier n’a été copié.');
    }

    public function storeCoverage(StoreInsuranceCoverageRequest $request, InsurancePolicy $policy, CreateInsuranceCoverage $action): RedirectResponse
    {
        $this->authorize('update', $policy);
        $action->handle($policy, $request->validated());

        return back()->with('status', 'Garantie ajoutée.');
    }

    public function editCoverage(InsurancePolicy $policy, InsurancePolicyCoverage $coverage): View
    {
        $this->authorize('update', $policy);
        abort_unless($coverage->insurance_policy_id === $policy->id, 404);

        return view('insurance.coverages.edit', compact('policy', 'coverage'));
    }

    public function updateCoverage(StoreInsuranceCoverageRequest $request, InsurancePolicy $policy, InsurancePolicyCoverage $coverage, UpdateInsuranceCoverage $action): RedirectResponse
    {
        $this->authorize('update', $policy);
        abort_unless($coverage->insurance_policy_id === $policy->id, 404);
        $action->handle($coverage, $request->validated());

        return redirect()->route('insurance.policies.show', $policy)->with('status', 'Garantie modifiée.');
    }

    public function archiveCoverage(Request $request, InsurancePolicy $policy, InsurancePolicyCoverage $coverage, ArchiveInsuranceCoverage $action): RedirectResponse
    {
        $this->authorize('update', $policy);
        abort_unless($coverage->insurance_policy_id === $policy->id, 404);
        $action->handle($coverage, $request->user()->id);

        return back()->with('status', 'Garantie archivée logiquement.');
    }

    public function createClaim(Request $request): View
    {
        $this->authorize('create', InsuranceClaim::class);
        $agencyId = $request->user()->agency_id;
        $scope = fn (Builder $query): Builder => $query->when($agencyId, fn (Builder $builder) => $builder->where('agency_id', $agencyId));

        return view('insurance.claims.create', [
            'agencies' => Agency::query()->when($agencyId, fn (Builder $query) => $query->whereKey($agencyId))->orderBy('name')->get(),
            'policies' => $scope(InsurancePolicy::with('vehicle'))->where('status', '<>', InsurancePolicyStatus::Draft)->latest()->get(),
            'contracts' => $scope(RentalContract::with(['vehicle', 'customer']))->latest()->limit(100)->get(),
            'damages' => $scope(DamageReport::with('rentalContract'))->latest()->limit(100)->get(),
        ]);
    }

    public function showClaim(InsuranceClaim $claim): View
    {
        $this->authorize('view', $claim);
        $claim->load(['policy.vehicle', 'damageReport', 'rentalContract', 'statusHistories.actor', 'documents.currentVersion']);

        return view('insurance.claims.show', ['claim' => $claim, 'documentTypes' => DocumentType::insuranceClaimTypes()]);
    }

    public function storeClaim(StoreInsuranceClaimRequest $request, CreateInsuranceClaim $action): RedirectResponse
    {
        $claim = $action->handle($request->validated(), $request->user()->id);

        return redirect()->route('insurance.claims.show', $claim)->with('status', 'Sinistre enregistré sans décision automatique de responsabilité.');
    }

    public function submit(InsuranceClaimTransitionRequest $request, InsuranceClaim $claim, SubmitInsuranceClaim $action): RedirectResponse
    {
        $this->authorize('manage', $claim);
        $action->handle($claim, $request->user()->id, $request->validated('note'));

        return back()->with('status', 'Sinistre soumis pour instruction.');
    }

    public function review(InsuranceClaimTransitionRequest $request, InsuranceClaim $claim, StartInsuranceClaimReview $action): RedirectResponse
    {
        $this->authorize('manage', $claim);
        $action->handle($claim, $request->user()->id, $request->validated('note'));

        return back()->with('status', 'Revue humaine du sinistre démarrée.');
    }

    public function approve(InsuranceClaimTransitionRequest $request, InsuranceClaim $claim, ApproveInsuranceClaim $action): RedirectResponse
    {
        $this->authorize('manage', $claim);
        $action->handle($claim, (string) $request->validated('approved_amount'), $request->user()->id, $request->validated('note'));

        return back()->with('status', 'Sinistre approuvé par décision humaine.');
    }

    public function reject(InsuranceClaimTransitionRequest $request, InsuranceClaim $claim, RejectInsuranceClaim $action): RedirectResponse
    {
        $this->authorize('manage', $claim);
        $action->handle($claim, $request->user()->id, $request->validated('note'));

        return back()->with('status', 'Sinistre rejeté par décision humaine.');
    }

    public function settle(InsuranceClaimTransitionRequest $request, InsuranceClaim $claim, SettleInsuranceClaim $action): RedirectResponse
    {
        $this->authorize('manage', $claim);
        $action->handle($claim, (string) $request->validated('settled_amount'), $request->user()->id, $request->validated('note'));

        return back()->with('status', 'Règlement du sinistre enregistré.');
    }

    public function close(InsuranceClaimTransitionRequest $request, InsuranceClaim $claim, CloseInsuranceClaim $action): RedirectResponse
    {
        $this->authorize('manage', $claim);
        $action->handle($claim, $request->user()->id, $request->validated('note'));

        return back()->with('status', 'Sinistre clôturé.');
    }

    private function policyFormData(Request $request, ?int $fixedAgencyId = null): array
    {
        $agencyId = $fixedAgencyId ?? $request->user()->agency_id;

        return [
            'agencies' => Agency::query()->when($agencyId, fn (Builder $query) => $query->whereKey($agencyId))->orderBy('name')->get(),
            'vehicles' => Vehicle::query()->when($agencyId, fn (Builder $query) => $query->where('agency_id', $agencyId))->orderBy('registration_number')->get(),
            'companies' => InsuranceCompany::where('is_active', true)->orderBy('name')->get(),
        ];
    }
}
