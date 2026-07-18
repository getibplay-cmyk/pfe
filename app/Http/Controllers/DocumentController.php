<?php

namespace App\Http\Controllers;

use App\Actions\Documents\AddDocumentVersion;
use App\Actions\Documents\ArchiveDocument;
use App\Actions\Documents\DownloadPrivateDocument;
use App\Actions\Documents\StorePrivateDocument;
use App\Enums\DocumentType;
use App\Models\Customer;
use App\Models\DamageReport;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\Driver;
use App\Models\InsuranceClaim;
use App\Models\InsurancePolicy;
use App\Models\MaintenanceOrder;
use App\Models\Vehicle;
use App\Models\VehicleInspection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function storeForVehicle(Request $request, Vehicle $vehicle, StorePrivateDocument $action): RedirectResponse
    {
        $this->authorize('update', $vehicle);

        return $this->store($request, $vehicle, $action);
    }

    public function storeForCustomer(Request $request, Customer $customer, StorePrivateDocument $action): RedirectResponse
    {
        $this->authorize('update', $customer);

        return $this->store($request, $customer, $action);
    }

    public function storeForDriver(Request $request, Driver $driver, StorePrivateDocument $action): RedirectResponse
    {
        $this->authorize('view', $driver);

        return $this->store($request, $driver, $action);
    }

    public function storeForInspection(Request $request, VehicleInspection $inspection, StorePrivateDocument $action): RedirectResponse
    {
        $this->authorize('manage', $inspection);

        return $this->store($request, $inspection, $action);
    }

    public function storeForDamage(Request $request, DamageReport $damage, StorePrivateDocument $action): RedirectResponse
    {
        $this->authorize('report', $damage);

        return $this->store($request, $damage, $action);
    }

    public function storeForMaintenance(Request $request, MaintenanceOrder $maintenance, StorePrivateDocument $action): RedirectResponse
    {
        $this->authorize('uploadDocument', $maintenance);
        $allowedTypes = collect(DocumentType::maintenanceTypes())->map->value->all();
        $data = $request->validate([
            'tenant_id' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'stored_path' => ['prohibited'],
            'documentable_type' => ['prohibited'],
            'document_type' => ['required', Rule::in($allowedTypes)],
            'title' => ['required', 'string', 'max:255'],
            'retention_until' => ['nullable', 'date'],
            'is_sensitive' => ['required', 'boolean'],
            'file' => ['required', 'file', 'max:'.config('documents.max_size_kb')],
        ]);
        $action->handle($maintenance, $data, $request->file('file'), $request->user()->id);

        return back()->with('status', 'Document privé de maintenance ajouté.');
    }

    public function storeForInsurancePolicy(Request $request, InsurancePolicy $policy, StorePrivateDocument $action): RedirectResponse
    {
        $this->authorize('uploadDocument', $policy);
        $this->storeInsuranceDocument($request, $policy, DocumentType::insurancePolicyTypes(), $action);

        return back()->with('status', 'Document privé de police ajouté.');
    }

    public function storeForInsuranceClaim(Request $request, InsuranceClaim $claim, StorePrivateDocument $action): RedirectResponse
    {
        $this->authorize('uploadDocument', $claim);
        $this->storeInsuranceDocument($request, $claim, DocumentType::insuranceClaimTypes(), $action);

        return back()->with('status', 'Document privé de sinistre ajouté.');
    }

    public function show(Request $request, Document $document): View
    {
        $this->authorize('view', $document);
        DocumentAccessLog::create(['document_id' => $document->id, 'document_version_id' => $document->current_version_id, 'user_id' => $request->user()->id, 'action' => 'view_metadata', 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
        $document->load('versions');

        return view('documents.show', compact('document'));
    }

    public function addVersion(Request $request, Document $document, AddDocumentVersion $action): RedirectResponse
    {
        $this->authorize('upload', $document);
        $request->validate(['tenant_id' => ['prohibited'], 'stored_path' => ['prohibited'], 'file' => ['required', 'file', 'max:'.config('documents.max_size_kb')]]);
        $action->handle($document, $request->file('file'), $request->user()->id);

        return back()->with('status', 'Nouvelle version ajoutée.');
    }

    public function download(Request $request, Document $document, DownloadPrivateDocument $action): StreamedResponse
    {
        $this->authorize('download', $document);

        return $action->handle($document, $request->user()->id);
    }

    public function destroy(Document $document, ArchiveDocument $action): RedirectResponse
    {
        $this->authorize('delete', $document);
        $owner = $document->documentable;
        $action->handle($document);

        $redirect = match (true) {
            $owner instanceof Customer => route('customers.show', $owner),
            $owner instanceof Driver => route('drivers.show', $owner),
            $owner instanceof MaintenanceOrder => route('maintenance.show', $owner),
            $owner instanceof InsurancePolicy => route('insurance.policies.show', $owner),
            $owner instanceof InsuranceClaim => route('insurance.claims.show', $owner),
            default => route('customers.index'),
        };

        return redirect($redirect)->with('status', 'Document archivé ; le fichier privé et ses versions sont conservés.');
    }

    private function store(Request $request, Model $documentable, StorePrivateDocument $action): RedirectResponse
    {
        $this->authorize('upload', Document::class);
        $data = $request->validate(['tenant_id' => ['prohibited'], 'stored_path' => ['prohibited'], 'documentable_type' => ['prohibited'], 'document_type' => ['required', Rule::enum(DocumentType::class)], 'title' => ['required', 'max:255'], 'retention_until' => ['nullable', 'date'], 'is_sensitive' => ['required', 'boolean'], 'file' => ['required', 'file', 'max:'.config('documents.max_size_kb')]]);
        $action->handle($documentable, $data, $request->file('file'), $request->user()->id);

        return back()->with('status', 'Document privé ajouté.');
    }

    private function storeInsuranceDocument(Request $request, Model $owner, array $types, StorePrivateDocument $action): void
    {
        $data = $request->validate([
            'tenant_id' => ['prohibited'], 'agency_id' => ['prohibited'], 'stored_path' => ['prohibited'], 'documentable_type' => ['prohibited'],
            'document_type' => ['required', Rule::in(collect($types)->map->value->all())],
            'title' => ['required', 'string', 'max:255'], 'retention_until' => ['nullable', 'date'],
            'is_sensitive' => ['required', 'boolean'], 'file' => ['required', 'file', 'max:'.config('documents.max_size_kb')],
        ]);
        $action->handle($owner, $data, $request->file('file'), $request->user()->id);
    }
}
