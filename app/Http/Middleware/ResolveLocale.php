<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $previous = app()->getLocale();
        $locale = $request->user()?->locale ?? $request->session()->get('locale', 'fr');
        app()->setLocale(in_array($locale, ['fr', 'ar'], true) ? $locale : 'fr');
        try {
            $response = $next($request);
            $response->headers->set('Content-Language', app()->getLocale());

            return $response;
        } finally {
            app()->setLocale($previous);
        }
    }
}
