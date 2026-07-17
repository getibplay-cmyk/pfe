<?php

namespace App\Http\Controllers;

use App\Actions\Customers\ArchiveDriver;
use App\Actions\Customers\CreateDriver;
use App\Actions\Customers\RejectDriverVerification;
use App\Actions\Customers\RestoreDriver;
use App\Actions\Customers\RevealDriverLicence;
use App\Actions\Customers\UpdateDriver;
use App\Actions\Customers\VerifyDriver;
use App\Http\Requests\Customers\RejectVerificationRequest;
use App\Http\Requests\Customers\StoreDriverRequest;
use App\Http\Requests\Customers\UpdateDriverRequest;
use App\Models\Customer;
use App\Models\Driver;
use App\Support\SensitiveData\IdentityProtector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

class DriverController extends Controller
{
    public function store(StoreDriverRequest $request, Customer $customer, CreateDriver $action): RedirectResponse
    {
        $this->authorize('update', $customer);
        $driver = $action->handle($customer, $request->validated());

        return redirect()->route('drivers.show', $driver)->with('status', 'Conducteur ajouté avec le statut en attente.');
    }

    public function show(Driver $driver, IdentityProtector $protector): View
    {
        $this->authorize('view', $driver);
        $driver->load(['customer', 'documents.currentVersion']);

        return view('drivers.show', compact('driver', 'protector'));
    }

    public function edit(Driver $driver): View
    {
        $this->authorize('update', $driver);

        return view('drivers.form', compact('driver'));
    }

    public function update(UpdateDriverRequest $request, Driver $driver, UpdateDriver $action): RedirectResponse
    {
        $this->authorize('update', $driver);
        $action->handle($driver, $request->validated());

        return redirect()->route('drivers.show', $driver)->with('status', 'Conducteur mis à jour.');
    }

    public function verify(Driver $driver, VerifyDriver $action): RedirectResponse
    {
        $this->authorize('verify', $driver);
        $action->handle($driver);

        return back()->with('status', 'Conducteur vérifié.');
    }

    public function reject(RejectVerificationRequest $request, Driver $driver, RejectDriverVerification $action): RedirectResponse
    {
        $this->authorize('verify', $driver);
        $action->handle($driver, $request->validated('reason'));

        return back()->with('status', 'Vérification du conducteur rejetée.');
    }

    public function destroy(Driver $driver, ArchiveDriver $action): RedirectResponse
    {
        $this->authorize('archive', $driver);
        $customerId = $driver->customer_id;
        $action->handle($driver);

        return redirect()->route('customers.show', $customerId)->with('status', 'Conducteur archivé.');
    }

    public function restore(int $driverId, RestoreDriver $action): RedirectResponse
    {
        $driver = Driver::withTrashed()->with('customer')->findOrFail($driverId);
        $this->authorize('restore', $driver);
        $customerId = $driver->customer_id;
        $action->handle($driver);

        return redirect()->route('customers.show', $customerId)->with('status', 'Conducteur restauré.');
    }

    public function licence(Driver $driver, RevealDriverLicence $action): Response
    {
        $this->authorize('viewIdentity', $driver);
        $licence = $action->handle($driver);

        return response()->view('drivers.licence', compact('driver', 'licence'))->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
