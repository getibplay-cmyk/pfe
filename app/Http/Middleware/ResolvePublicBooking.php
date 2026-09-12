<?php

namespace App\Http\Middleware;

use App\Models\PublicBookingProfile;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ResolvePublicBooking
{
    public function handle(Request $request, Closure $next)
    {
        $profile = PublicBookingProfile::withoutGlobalScopes()->where('slug', $request->route('company'))->where('enabled', true)->firstOrFail();
        abort_unless(DB::table('tenants')->where('id', $profile->tenant_id)->where('status', 'active')->whereNull('deleted_at')->exists()
            && DB::table('agencies')->where('tenant_id', $profile->tenant_id)->where('id', $profile->agency_id)->where('is_active', true)->whereNull('deleted_at')->exists(), 404);
        $request->attributes->set('booking_profile', $profile);
        $previous = $request->getUserResolver();
        $request->setUserResolver(fn () => null);
        try {
            $response = app(TenantContext::class)->run($profile->tenant_id, fn () => $next($request), $profile->agency_id);
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        } finally {
            $request->setUserResolver($previous);
        }
    }
}
