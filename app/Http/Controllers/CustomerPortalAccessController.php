<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Customer;
use App\Models\CustomerPortalAccess;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\CustomerPortalContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class CustomerPortalAccessController extends Controller
{
    public function show(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        return view('customers.portal-access', ['customer' => $customer,
            'accesses' => CustomerPortalAccess::where('customer_id', $customer->id)->latest()->limit(10)->get()]);
    }

    public function store(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);
        $request->validate(['tenant_id' => ['prohibited'], 'customer_id' => ['prohibited'], 'agency_id' => ['prohibited']]);
        abort_unless($customer->agency_id && Agency::whereKey($customer->agency_id)->where('is_active', true)->exists(), 422, 'Rattachez ce client à une agence active avant de créer un accès.');
        $access = DB::transaction(function () use ($request, $customer) {
            Customer::whereKey($customer)->lockForUpdate()->firstOrFail();
            CustomerPortalAccess::where('customer_id', $customer->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'session_hash' => null]);
            $access = CustomerPortalAccess::create(['customer_id' => $customer->id, 'agency_id' => $customer->agency_id,
                'issued_by' => $request->user()->id, 'expires_at' => now()->addHours(48)]);
            app(AuditRecorder::class)->record('customer.portal_access_created', $customer);

            return $access;
        });

        return redirect()->route('customers.portal-access.show', $customer)->with('portal_url', URL::temporarySignedRoute('portal.enter', $access->expires_at, ['access' => $access->id]));
    }

    public function revoke(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);
        DB::transaction(function () use ($customer) {
            Customer::whereKey($customer)->lockForUpdate()->firstOrFail();
            CustomerPortalAccess::where('customer_id', $customer->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'session_hash' => null]);
            app(AuditRecorder::class)->record('customer.portal_access_revoked', $customer);
        });

        return back()->with('status', 'Tous les accès au portail de ce client ont été révoqués.');
    }

    public function enter(Request $request, string $access)
    {
        $grant = CustomerPortalAccess::withoutGlobalScopes()->findOrFail($access);
        app(CustomerPortalContext::class)->customer($grant);
        abort_if($grant->consumed_at !== null, 403, 'Ce lien a déjà été utilisé.');

        return view('portal.enter', ['entryUrl' => $request->fullUrl()]);
    }

    public function exchange(Request $request, string $access)
    {
        $proof = Str::random(64);
        DB::transaction(function () use ($access, $proof) {
            $grant = CustomerPortalAccess::withoutGlobalScopes()->lockForUpdate()->findOrFail($access);
            app(CustomerPortalContext::class)->customer($grant);
            abort_if($grant->consumed_at !== null, 403, 'Ce lien a déjà été utilisé.');
            app(TenantContext::class)->run((int) $grant->tenant_id, function () use ($grant, $proof) {
                $grant->forceFill(['consumed_at' => now(), 'session_hash' => hash('sha256', $proof), 'session_expires_at' => now()->addMinutes(30)])->save();
            });
        });
        $request->session()->regenerate();
        $request->session()->put('customer_portal', ['id' => $access, 'proof' => $proof]);

        return redirect()->route('portal.home');
    }

    public function logout(Request $request)
    {
        $access = $request->attributes->get('portal_access');
        $access->forceFill(['revoked_at' => now(), 'session_hash' => null])->save();
        $request->session()->forget('customer_portal');
        $request->session()->regenerate();

        return redirect()->route('home')->with('status', 'Vous avez quitté votre espace locataire.');
    }
}
