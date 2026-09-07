<?php

namespace App\Http\Controllers;

use App\Models\PlatformBilling\SaasPlan;
use App\Support\PlatformBilling\SaasPlanEntitlements;
use Illuminate\View\View;

class PublicSiteController extends Controller
{
    public function __construct(private readonly SaasPlanEntitlements $entitlements) {}

    public function home(): View
    {
        return view('public.home', [
            'plans' => $this->plans()->take(3),
        ]);
    }

    public function pricing(): View
    {
        return view('public.pricing', [
            'plans' => $this->plans(),
        ]);
    }

    public function subscription(): View
    {
        return view('public.subscription', [
            'plans' => $this->plans(),
        ]);
    }

    private function plans()
    {
        return SaasPlan::query()
            ->where('is_active', true)
            ->orderBy('price_amount')
            ->orderBy('name')
            ->get()
            ->each(function (SaasPlan $plan): void {
                $plan->setAttribute('entitlement_summary', $this->entitlements->normalize($plan->entitlements));
            });
    }
}
