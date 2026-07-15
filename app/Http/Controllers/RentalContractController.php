<?php

namespace App\Http\Controllers;

use App\Actions\Rentals\AcceptRentalContract;
use App\Actions\Rentals\ActivateRentalContract;
use App\Actions\Rentals\AttachContractVersionDocument;
use App\Actions\Rentals\CalculateReturnCharges;
use App\Actions\Rentals\CancelDraftRentalContract;
use App\Actions\Rentals\CompareVehicleInspections;
use App\Actions\Rentals\CreateContractVersion;
use App\Actions\Rentals\CreateRentalContractFromReservation;
use App\Actions\Rentals\MarkContractReady;
use App\Actions\Rentals\MarkRentalReturned;
use App\Enums\AcceptanceMethod;
use App\Enums\RentalContractStatus;
use App\Models\RentalContract;
use App\Models\Reservation;
use App\Models\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RentalContractController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', RentalContract::class);
        $contracts = RentalContract::with(['customer', 'vehicle', 'agency'])
            ->when($request->user()->agency_id, fn ($query, $id) => $query->where('agency_id', $id))
            ->when($request->string('status')->isNotEmpty(), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->string('q')->isNotEmpty(), fn ($query) => $query->where('contract_number', 'ilike', '%'.$request->string('q').'%'))
            ->latest()->paginate(20)->withQueryString();

        return view('contracts.index', ['contracts' => $contracts, 'statuses' => RentalContractStatus::cases()]);
    }

    public function show(RentalContract $contract, CompareVehicleInspections $compare): View
    {
        $this->authorize('view', $contract);
        $contract->load(['reservation', 'customer', 'vehicle', 'agency', 'drivers.driver', 'versions', 'currentVersion.document.currentVersion', 'acceptances', 'inspections.items', 'damages.statusHistories', 'charges', 'statusHistories.actor', 'vehicleBlock']);

        $departure = $contract->inspections->firstWhere('inspection_type.value', 'departure');
        $return = $contract->inspections->firstWhere('inspection_type.value', 'return');
        $comparison = $departure && $return ? $compare->handle($departure, $return) : null;

        return view('contracts.show', ['contract' => $contract, 'acceptanceMethods' => AcceptanceMethod::cases(), 'comparison' => $comparison]);
    }

    public function store(Request $request, Reservation $reservation, CreateRentalContractFromReservation $action): RedirectResponse
    {
        $this->authorize('create', RentalContract::class);
        $this->authorize('view', $reservation);
        $request->validate(['tenant_id' => ['prohibited']]);
        $contract = $action->handle($reservation, $request->user()->id);

        return redirect()->route('contracts.show', $contract)->with('status', 'Contrat créé depuis la réservation confirmée.');
    }

    public function version(Request $request, RentalContract $contract, CreateContractVersion $action): RedirectResponse
    {
        $this->authorize('version', $contract);
        $data = $request->validate(['tenant_id' => ['prohibited'], 'change_reason' => ['required', 'string', 'max:1000']]);
        $action->handle($contract, $request->user()->id, $data['change_reason']);

        return back()->with('status', 'Nouvelle version créée sans modifier les versions précédentes.');
    }

    public function ready(Request $request, RentalContract $contract, MarkContractReady $action): RedirectResponse
    {
        $this->authorize('version', $contract);
        $action->handle($contract, $request->user()->id);

        return back()->with('status', 'Contrat prêt à accepter.');
    }

    public function versionDocument(Request $request, RentalContract $contract, AttachContractVersionDocument $action): RedirectResponse
    {
        $this->authorize('version', $contract);
        $this->authorize('upload', Document::class);
        $request->validate(['tenant_id' => ['prohibited'], 'stored_path' => ['prohibited'], 'file' => ['required', 'file', 'max:'.config('documents.max_size_kb')]]);
        $action->handle($contract, $request->file('file'), $request->user()->id);

        return back()->with('status', 'Document privé associé à la version contractuelle.');
    }

    public function accept(Request $request, RentalContract $contract, AcceptRentalContract $action): RedirectResponse
    {
        $this->authorize('accept', $contract);
        $data = $request->validate(['tenant_id' => ['prohibited'], 'accepted_by_name' => ['required', 'string', 'max:255'], 'acceptance_method' => ['required', Rule::enum(AcceptanceMethod::class)]]);
        $action->handle($contract, [...$data, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()], $request->user()->id);

        return back()->with('status', 'Acceptation enregistrée et version verrouillée.');
    }

    public function activate(Request $request, RentalContract $contract, ActivateRentalContract $action): RedirectResponse
    {
        $this->authorize('activate', $contract);
        $action->handle($contract, $request->user()->id);

        return back()->with('status', 'Contrat activé.');
    }

    public function charges(Request $request, RentalContract $contract, CalculateReturnCharges $action): RedirectResponse
    {
        $this->authorize('return', $contract);
        abort_unless($request->user()->hasPermission('charge.review'), 403);
        $data = $request->validate(['cleaning_approved' => ['nullable', 'boolean'], 'cleaning_amount' => ['nullable', 'decimal:0,2', 'min:0']]);
        $action->handle($contract, $data);

        return back()->with('status', 'Frais de retour recalculés et laissés en attente de décision.');
    }

    public function returned(Request $request, RentalContract $contract, MarkRentalReturned $action): RedirectResponse
    {
        $this->authorize('return', $contract);
        abort_unless($request->user()->hasPermission('charge.review'), 403);
        $data = $request->validate(['approved_charge_ids' => ['array'], 'approved_charge_ids.*' => ['integer'], 'rejected_charge_ids' => ['array'], 'rejected_charge_ids.*' => ['integer'], 'reason' => ['nullable', 'string', 'max:1000']]);
        $action->handle($contract, $data, $request->user()->id);

        return back()->with('status', 'Retour finalisé. Le bloc de ce contrat a été libéré.');
    }

    public function cancel(Request $request, RentalContract $contract, CancelDraftRentalContract $action): RedirectResponse
    {
        $this->authorize('cancel', $contract);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $action->handle($contract, $data['reason'], $request->user()->id);

        return back()->with('status', 'Contrat brouillon annulé.');
    }

    public function print(RentalContract $contract): View
    {
        $this->authorize('view', $contract);
        $contract->load(['customer', 'vehicle', 'drivers.driver', 'currentVersion', 'acceptances']);

        return view('contracts.print', compact('contract'));
    }
}
