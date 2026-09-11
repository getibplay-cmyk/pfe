<?php

namespace App\Http\Controllers;

use App\Actions\Documents\DownloadPrivateDocument;
use App\Actions\Documents\StorePrivateDocument;
use App\Enums\DocumentType;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\CustomerPortalAccess;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\RentalContract;
use App\Support\Tenancy\CustomerPortalContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerPortalController extends Controller
{
    public function index(Request $request)
    {
        $customer = $request->attributes->get('portal_customer');

        return view('portal.home', [
            'customer' => $customer,
            'agency' => Agency::findOrFail($customer->agency_id),
            'reservations' => $customer->reservations()->where('agency_id', $customer->agency_id)->whereNotIn('status', ['draft', 'pending'])->with('vehicle:id,registration_number,brand,model')->latest('starts_at')->paginate(10, ['*'], 'reservations_page'),
            'contracts' => $customer->rentalContracts()->where('agency_id', $customer->agency_id)->whereNotNull('accepted_at')->with('currentVersion')->latest('expected_start_at')->paginate(10, ['*'], 'contracts_page'),
            'invoices' => Invoice::where('customer_id', $customer->id)->where('agency_id', $customer->agency_id)->whereNotNull('issued_at')->whereNotIn('status', ['draft', 'void'])->latest('issued_at')->paginate(10, ['*'], 'invoices_page'),
            'uploads' => Document::where('documentable_type', $customer->getMorphClass())->where('documentable_id', $customer->id)
                ->whereIn('id', DB::table('customer_portal_uploads')->where('tenant_id', $customer->tenant_id)->where('customer_id', $customer->id)->select('document_id'))
                ->latest()->limit(20)->get(),
        ]);
    }

    public function invoice(Request $request, int $invoice)
    {
        $customer = $request->attributes->get('portal_customer');
        $invoice = Invoice::where('customer_id', $customer->id)->where('agency_id', $customer->agency_id)
            ->whereNotNull('issued_at')->whereNotIn('status', ['draft', 'void'])->with('lines')->findOrFail($invoice);

        return view('portal.invoice', compact('invoice', 'customer'));
    }

    public function contract(Request $request, int $contract, DownloadPrivateDocument $download)
    {
        $customer = $request->attributes->get('portal_customer');
        $contract = RentalContract::where('customer_id', $customer->id)->where('agency_id', $customer->agency_id)->whereNotNull('accepted_at')->findOrFail($contract);
        $version = $contract->currentVersion()->whereNotNull('locked_at')->firstOrFail();
        $document = $version->document()->where('agency_id', $customer->agency_id)->firstOrFail();

        return $download->handle($document, null);
    }

    public function upload(Request $request, StorePrivateDocument $store)
    {
        $customer = $request->attributes->get('portal_customer');
        $access = $request->attributes->get('portal_access');
        $data = $request->validate([
            'tenant_id' => ['prohibited'], 'agency_id' => ['prohibited'], 'customer_id' => ['prohibited'],
            'documentable_type' => ['prohibited'], 'documentable_id' => ['prohibited'], 'stored_path' => ['prohibited'],
            'document_type' => ['required', Rule::in([DocumentType::CustomerIdentity->value, DocumentType::Other->value])],
            'file' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);
        DB::transaction(function () use ($request, $store, $customer, $access, $data) {
            Customer::whereKey($customer)->lockForUpdate()->firstOrFail();
            $lockedAccess = CustomerPortalAccess::whereKey($access)->lockForUpdate()->firstOrFail();
            app(CustomerPortalContext::class)->customer($lockedAccess);
            abort_unless($lockedAccess->session_expires_at?->gt(now()), 403);
            if (DB::table('customer_portal_uploads')->where('tenant_id', $customer->tenant_id)->where('customer_id', $customer->id)->where('created_at', '>=', now()->subDay())->count() >= 10) {
                throw ValidationException::withMessages(['file' => 'Vous avez atteint la limite de 10 documents par jour.']);
            }
            $document = $store->handle($customer, ['document_type' => $data['document_type'],
                'title' => $data['document_type'] === 'customer_identity' ? 'Identité transmise par le locataire' : 'Justificatif transmis par le locataire', 'is_sensitive' => true], $request->file('file'), null);
            DB::table('customer_portal_uploads')->insert(['tenant_id' => $customer->tenant_id, 'access_id' => $access->id,
                'customer_id' => $customer->id, 'document_id' => $document->id, 'created_at' => now(), 'updated_at' => now()]);
        });

        return redirect()->route('portal.home')->with('status', 'Document transmis. Votre agence doit encore le vérifier.');
    }

    public function document(Request $request, int $document, DownloadPrivateDocument $download)
    {
        $customer = $request->attributes->get('portal_customer');
        abort_unless(DB::table('customer_portal_uploads')->where('tenant_id', $customer->tenant_id)->where('customer_id', $customer->id)->where('document_id', $document)->exists(), 404);
        $document = Document::where('agency_id', $customer->agency_id)->where('documentable_type', $customer->getMorphClass())->where('documentable_id', $customer->id)->findOrFail($document);

        return $download->handle($document, null);
    }
}
