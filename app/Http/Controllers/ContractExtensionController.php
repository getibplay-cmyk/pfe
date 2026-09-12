<?php

namespace App\Http\Controllers;

use App\Models\ContractExtension;
use App\Support\Rentals\ActiveCustomerPortal;
use Illuminate\Http\Request;

final class ContractExtensionController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('contract.view'), 403);
        $query = ContractExtension::with('rentalContract.vehicle')->where('tenant_id', $request->user()->tenant_id);
        if ($request->user()->agency_id !== null) {
            $query->where('agency_id', $request->user()->agency_id);
        }

        return view('contracts.extensions', ['extensions' => $query->orderByRaw("CASE WHEN status = 'requested' THEN 0 WHEN status = 'offered' THEN 1 ELSE 2 END")->latest()->paginate(20)]);
    }

    public function show(Request $request, ContractExtension $extension, ActiveCustomerPortal $portal)
    {
        $portal->authorizeReview($request->user(), $extension->rentalContract);

        return view('contracts.extension', ['extension' => $extension->load('rentalContract.vehicle'), 'contract' => $extension->rentalContract]);
    }

    public function offer(Request $request, ContractExtension $extension, ActiveCustomerPortal $portal)
    {
        $data = $request->validate(['tenant_id' => ['prohibited'], 'additional_amount' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'], 'included_km' => ['required', 'integer', 'between:0,1000000'], 'agency_note' => ['nullable', 'string', 'max:1000'], 'document' => ['required', 'file', 'mimes:pdf', 'extensions:pdf', 'max:5120'], 'document_matches' => ['accepted']]);
        $portal->offer($request->user(), $extension, $data, $request->file('document'));

        return to_route('contract-extensions.show', $extension)->with('status', __('Proposition disponible dans le portail. La disponibilité sera à nouveau vérifiée lors de l’acceptation.'));
    }

    public function reject(Request $request, ContractExtension $extension, ActiveCustomerPortal $portal)
    {
        $data = $request->validate(['agency_note' => ['required', 'string', 'min:5', 'max:1000']]);
        $portal->resolve($extension, 'rejected', reviewer: $request->user(), note: $data['agency_note']);

        return to_route('contract-extensions.index')->with('status', __('Demande refusée.'));
    }
}
