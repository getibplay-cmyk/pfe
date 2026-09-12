<?php

namespace App\Http\Controllers;

use App\Actions\Documents\DownloadPrivateDocument;
use App\Models\ContractExtension;
use App\Support\Rentals\ActiveCustomerPortal;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ActiveCustomerPortalController extends Controller
{
    public function show(Request $request, int $contract, ActiveCustomerPortal $portal)
    {
        $access = $request->attributes->get('portal_access');
        $contract = $portal->contract($access, $contract)->load('currentVersion.document', 'vehicle');

        return view('portal.contract', ['contract' => $contract, 'extensions' => ContractExtension::where('rental_contract_id', $contract->id)->latest()->paginate(10), 'proposal' => $contract->status->value === 'ready' && $contract->currentVersion?->document_id ? $portal->acceptanceProposal($access, $contract) : null]);
    }

    public function document(Request $request, int $contract, int $version, ActiveCustomerPortal $portal, DownloadPrivateDocument $download)
    {
        $contract = $portal->contract($request->attributes->get('portal_access'), $contract);
        $version = $contract->versions()->where(fn ($query) => $query->whereNotNull('locked_at')->orWhere('id', $contract->current_version_id))->findOrFail($version);

        return $download->handle($version->document()->firstOrFail(), null);
    }

    public function accept(Request $request, int $contract, ActiveCustomerPortal $portal)
    {
        $data = $this->acceptance($request) + $request->validate(['proposal' => ['required', 'string', 'max:10000']]);
        $portal->accept($request->attributes->get('portal_access'), $contract, $data['proposal'], $data);

        return to_route('portal.contract.show', $contract)->with('status', __('Votre acceptation a été enregistrée.'));
    }

    public function requestExtension(Request $request, int $contract, ActiveCustomerPortal $portal)
    {
        $data = $request->validate(['tenant_id' => ['prohibited'], 'agency_id' => ['prohibited'], 'customer_id' => ['prohibited'], 'requested_return_at' => ['required', 'date'], 'customer_note' => ['nullable', 'string', 'max:1000']]);
        $portal->requestExtension($request->attributes->get('portal_access'), $contract, $data);

        return to_route('portal.contract.show', $contract)->with('status', __('Demande transmise. Les dates restent inchangées jusqu’à votre acceptation de la proposition de l’agence.'));
    }

    public function extensionDocument(Request $request, int $contract, int $extension, ActiveCustomerPortal $portal, DownloadPrivateDocument $download)
    {
        $contract = $portal->contract($request->attributes->get('portal_access'), $contract);
        $extension = ContractExtension::where('rental_contract_id', $contract->id)->whereIn('status', ['offered', 'accepted'])->findOrFail($extension);

        return $download->handle($portal->extensionDocument($extension), null);
    }

    public function acceptExtension(Request $request, int $contract, int $extension, ActiveCustomerPortal $portal)
    {
        $data = $this->acceptance($request);
        try {
            $portal->acceptExtension($request->attributes->get('portal_access'), $contract, $extension, $data);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23P01') {
                throw $exception;
            }
            throw ValidationException::withMessages(['requested_return_at' => __('Le véhicule vient d’être réservé. Contactez votre agence pour une autre proposition.')]);
        }

        return to_route('portal.contract.show', $contract)->with('status', __('Prolongation acceptée. Le planning et le contrat ont été mis à jour.'));
    }

    public function withdraw(Request $request, int $contract, int $extension, ActiveCustomerPortal $portal)
    {
        $access = $request->attributes->get('portal_access');
        $contract = $portal->contract($access, $contract);
        $extension = ContractExtension::where('rental_contract_id', $contract->id)->findOrFail($extension);
        $portal->resolve($extension, 'cancelled', access: $access);

        return to_route('portal.contract.show', $contract)->with('status', __('Demande retirée.'));
    }

    private function acceptance(Request $request): array
    {
        $data = $request->validate(['tenant_id' => ['prohibited'], 'customer_id' => ['prohibited'], 'actor_id' => ['prohibited'], 'accepted_by_name' => ['required', 'string', 'min:3', 'max:200'], 'consent' => ['accepted']]);

        return [...$data, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()];
    }
}
