<?php

namespace App\Http\Middleware;

use App\Support\Intelligence\J11\J11ContractDemoGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureJ11ContractDemoEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app(J11ContractDemoGate::class)->enabled(), 404);

        return $next($request);
    }
}
