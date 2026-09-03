<?php

namespace App\Http\Controllers;

use App\Actions\PlatformBilling\ProcessCmiCallback;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CmiCallbackController extends Controller
{
    public function __invoke(Request $request, ProcessCmiCallback $process): Response
    {
        if ((int) $request->server('CONTENT_LENGTH', 0) > 65_536 || count($request->all()) > 80) {
            return response('ACTION=DECLINE', 413)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $parameters = [];
        foreach ($request->all() as $key => $value) {
            if (! is_string($key)
                || preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/', $key) !== 1
                || (! is_scalar($value) && $value !== null)
                || strlen((string) $value) > 2_048) {
                return response('ACTION=DECLINE', 422)->header('Content-Type', 'text/plain; charset=UTF-8');
            }
            $parameters[$key] = $value;
        }

        $result = $process->handle($parameters);

        return response($result['body'], $result['http_status'])
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Cache-Control', 'no-store, private');
    }
}
