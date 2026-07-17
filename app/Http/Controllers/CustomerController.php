<?php

namespace App\Http\Controllers;

use App\Actions\Customers\ArchiveCustomer;
use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\RejectCustomerVerification;
use App\Actions\Customers\RestoreCustomer;
use App\Actions\Customers\UpdateCustomer;
use App\Actions\Customers\VerifyCustomer;
use App\Enums\CustomerType;
use App\Http\Requests\Customers\RejectVerificationRequest;
use App\Http\Requests\Customers\StoreCustomerRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Models\Agency;
use App\Models\Customer;
use App\Support\Audit\AuditRecorder;
use App\Support\SensitiveData\IdentityProtector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request, IdentityProtector $protector): View
    {
        $this->authorize('viewAny', Customer::class);
        $query = match ($request->string('status')->toString()) {
            'archived' => Customer::onlyTrashed(),
            'all' => Customer::withTrashed(),
            default => Customer::query(),
        };
        $customers = $query
            ->when($request->user()->agency_id, fn ($builder, $agencyId) => $builder->where('agency_id', $agencyId))
            ->when($request->string('q')->isNotEmpty(), fn ($builder) => $builder->where(fn ($search) => $search->where('last_name', 'ilike', '%'.$request->q.'%')->orWhere('company_name', 'ilike', '%'.$request->q.'%')))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('customers.index', compact('customers', 'protector'));
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Customer::class);

        return view('customers.form', $this->formData($request, new Customer));
    }

    public function store(StoreCustomerRequest $request, CreateCustomer $action): RedirectResponse
    {
        $this->authorize('create', Customer::class);
        $customer = $action->handle($request->validated());

        return redirect()->route('customers.show', $customer)->with('status', 'Client créé avec le statut en attente.');
    }

    public function show(Customer $customer, IdentityProtector $protector): View
    {
        $this->authorize('view', $customer);
        $customer->load([
            'drivers' => fn ($query) => $query->withTrashed()->with('documents.currentVersion')->orderByDesc('is_primary')->orderBy('id'),
            'documents.currentVersion',
        ]);

        return view('customers.show', compact('customer', 'protector'));
    }

    public function edit(Request $request, Customer $customer): View
    {
        $this->authorize('update', $customer);

        return view('customers.form', $this->formData($request, $customer));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer, UpdateCustomer $action): RedirectResponse
    {
        $this->authorize('update', $customer);
        $action->handle($customer, $request->validated());

        return redirect()->route('customers.show', $customer)->with('status', 'Client mis à jour.');
    }

    public function verify(Customer $customer, VerifyCustomer $action): RedirectResponse
    {
        $this->authorize('verify', $customer);
        $action->handle($customer, request()->user()->id);

        return back()->with('status', 'Client vérifié.');
    }

    public function reject(RejectVerificationRequest $request, Customer $customer, RejectCustomerVerification $action): RedirectResponse
    {
        $this->authorize('verify', $customer);
        $action->handle($customer, $request->validated('reason'));

        return back()->with('status', 'Vérification du client rejetée.');
    }

    public function destroy(Customer $customer, ArchiveCustomer $action): RedirectResponse
    {
        $this->authorize('archive', $customer);
        $action->handle($customer);

        return redirect()->route('customers.index')->with('status', 'Client archivé sans suppression de son historique.');
    }

    public function restore(int $customerId, RestoreCustomer $action): RedirectResponse
    {
        $customer = Customer::withTrashed()->findOrFail($customerId);
        $this->authorize('restore', $customer);
        $action->handle($customer);

        return redirect()->route('customers.show', $customerId)->with('status', 'Client restauré.');
    }

    public function identity(Customer $customer, IdentityProtector $protector, AuditRecorder $audit): Response
    {
        $this->authorize('viewIdentity', $customer);
        $audit->record('customer.identity.viewed', $customer);
        $identity = $customer->identity_number_encrypted ? $protector->reveal($customer->identity_number_encrypted) : null;

        return response()->view('customers.identity', compact('customer', 'identity'))->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    private function formData(Request $request, Customer $customer): array
    {
        return [
            'customer' => $customer,
            'agencies' => Agency::query()->when($request->user()->agency_id, fn ($query, $agencyId) => $query->whereKey($agencyId))->where('is_active', true)->orderBy('name')->get(),
            'types' => CustomerType::cases(),
        ];
    }
}
