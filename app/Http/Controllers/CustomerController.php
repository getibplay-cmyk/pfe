<?php

namespace App\Http\Controllers;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\UpdateCustomer;
use App\Enums\CustomerType;
use App\Enums\VerificationStatus;
use App\Models\Agency;
use App\Models\Customer;
use App\Support\Audit\AuditRecorder;
use App\Support\SensitiveData\IdentityProtector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request, IdentityProtector $protector): View
    {
        $this->authorize('viewAny', Customer::class);
        $customers = Customer::when($request->user()->agency_id, fn ($q, $agencyId) => $q->where('agency_id', $agencyId))->when($request->string('q')->isNotEmpty(), fn ($q) => $q->where(fn ($s) => $s->where('last_name', 'ilike', '%'.$request->q.'%')->orWhere('company_name', 'ilike', '%'.$request->q.'%')))->orderByDesc('id')->paginate(20)->withQueryString();

        return view('customers.index', compact('customers', 'protector'));
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Customer::class);

        return view('customers.form', $this->formData($request, new Customer));
    }

    public function store(Request $request, CreateCustomer $action): RedirectResponse
    {
        $this->authorize('create', Customer::class);
        $customer = $action->handle($this->validated($request));

        return redirect()->route('customers.show', $customer)->with('status', 'Client créé.');
    }

    public function show(Customer $customer, IdentityProtector $protector): View
    {
        $this->authorize('view', $customer);
        $customer->load(['drivers', 'documents.currentVersion']);

        return view('customers.show', compact('customer', 'protector'));
    }

    public function edit(Request $request, Customer $customer): View
    {
        $this->authorize('update', $customer);

        return view('customers.form', $this->formData($request, $customer));
    }

    public function update(Request $request, Customer $customer, UpdateCustomer $action): RedirectResponse
    {
        $this->authorize('update', $customer);
        $action->handle($customer, $this->validated($request, $customer));

        return redirect()->route('customers.show', $customer)->with('status', 'Client mis à jour.');
    }

    public function identity(Request $request, Customer $customer, IdentityProtector $protector, AuditRecorder $audit): View
    {
        $this->authorize('viewIdentity', $customer);
        $audit->record('customer.identity.viewed', $customer);
        $identity = $customer->identity_number_encrypted ? $protector->reveal($customer->identity_number_encrypted) : null;

        return view('customers.identity', compact('customer', 'identity'));
    }

    private function formData(Request $request, Customer $customer): array
    {
        return ['customer' => $customer, 'agencies' => Agency::query()->when($request->user()->agency_id, fn ($query, $agencyId) => $query->whereKey($agencyId))->orderBy('name')->get(), 'types' => CustomerType::cases(), 'verificationStatuses' => VerificationStatus::cases()];
    }

    private function validated(Request $request, ?Customer $customer = null): array
    {
        return $request->validate(['tenant_id' => ['prohibited'], 'agency_id' => ['nullable', 'integer'], 'customer_type' => ['required', Rule::enum(CustomerType::class)], 'first_name' => ['nullable', 'required_if:customer_type,individual', 'max:100'], 'last_name' => ['nullable', 'required_if:customer_type,individual', 'max:100'], 'company_name' => ['nullable', 'required_if:customer_type,company', 'max:255'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'max:50'], 'address' => ['nullable', 'max:2000'], 'city' => ['nullable', 'max:100'], 'nationality' => ['nullable', 'max:100'], 'birth_date' => ['nullable', 'date', 'before:today'], 'identity_type' => ['nullable', 'max:50'], 'identity_number' => [$customer ? 'nullable' : 'nullable', 'string', 'max:100'], 'verification_status' => ['required', Rule::enum(VerificationStatus::class)], 'notes' => ['nullable', 'max:5000']]);
    }
}
