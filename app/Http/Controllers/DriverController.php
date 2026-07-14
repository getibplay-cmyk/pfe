<?php

namespace App\Http\Controllers;

use App\Actions\Customers\CreateDriver;
use App\Enums\VerificationStatus;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DriverController extends Controller
{
    public function store(Request $request, Customer $customer, CreateDriver $action): RedirectResponse
    {
        $this->authorize('update', $customer);
        $data = $request->validate(['tenant_id' => ['prohibited'], 'first_name' => ['required', 'max:100'], 'last_name' => ['required', 'max:100'], 'birth_date' => ['nullable', 'date', 'before:today'], 'licence_number' => ['required', 'max:100'], 'licence_category' => ['nullable', 'max:20'], 'licence_issued_at' => ['nullable', 'date'], 'licence_expires_at' => ['required', 'date'], 'verification_status' => ['required', Rule::enum(VerificationStatus::class)], 'is_primary' => ['required', 'boolean']]);
        $action->handle($customer, $data);

        return back()->with('status', 'Conducteur ajouté.');
    }
}
