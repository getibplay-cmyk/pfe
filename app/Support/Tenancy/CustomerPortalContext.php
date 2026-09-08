<?php

namespace App\Support\Tenancy;

use App\Models\Customer;
use App\Models\CustomerPortalAccess;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CustomerPortalContext
{
    /** The UUID or browser parameters never provide a trusted tenant context. */
    public function customer(CustomerPortalAccess $access): Customer
    {
        abort_if($access->revoked_at !== null || $access->expires_at->lte(now()), 403, 'Cet accès a expiré ou a été révoqué.');
        abort_unless(DB::table('tenants')->where('id', $access->tenant_id)->where('status', 'active')->whereNull('deleted_at')->exists(), 403);
        abort_unless(DB::table('agencies')->where('tenant_id', $access->tenant_id)->where('id', $access->agency_id)->where('is_active', true)->whereNull('deleted_at')->exists(), 403);
        $issuer = User::with('role.permissions')->where('tenant_id', $access->tenant_id)->where('is_active', true)->find($access->issued_by);
        abort_unless($issuer && ! $issuer->is_platform_admin && $issuer->hasPermission('customer.update')
            && ($issuer->agency_id === null || $issuer->agency_id === $access->agency_id), 403);

        return Customer::withoutGlobalScopes()->where('tenant_id', $access->tenant_id)->where('agency_id', $access->agency_id)
            ->whereNull('deleted_at')->findOrFail($access->customer_id);
    }
}
