<?php

namespace App\Http\Controllers;

use App\Models\PlatformBilling\SaasInvoice;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class SaasInvoiceController extends Controller
{
    public function __invoke(Request $request, SaasInvoice $invoice): View
    {
        $actor = $request->user();
        abort_unless($actor->is_platform_admin || ($actor->isTenantOwner() && ($actor->role?->is_active ?? false)
            && $actor->tenant_id === $invoice->tenant_id), 404);

        return view('tenant.saas-invoice', compact('invoice'));
    }
}
