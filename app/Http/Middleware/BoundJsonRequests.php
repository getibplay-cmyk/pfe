<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

final class BoundJsonRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isJson()) {
            $max = 1_048_576;
            abort_if((int) $request->headers->get('Content-Length', 0) > $max, 413);
            $body = $request->getContent();
            abort_if(strlen($body) > $max, 413);
            if ($body !== '') {
                try {
                    $decoded = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    abort(422, __('Le contenu JSON est invalide ou trop complexe.'));
                }
                abort_unless($decoded instanceof \stdClass, 422, __('Le contenu JSON doit être un objet.'));
            }
        }

        return $next($request);
    }
}
