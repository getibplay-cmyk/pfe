<?php

namespace App\Http\Controllers;

use App\Models\PlatformBilling\SaasPaymentAttempt;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CmiReturnController extends Controller
{
    public function __invoke(Request $request, SaasPaymentAttempt $attempt): View
    {
        $attempt->load('subscription.plan');

        return view('public.cmi-return', [
            'attempt' => $attempt,
            'reportedResult' => $request->string('result')->toString(),
        ]);
    }
}
