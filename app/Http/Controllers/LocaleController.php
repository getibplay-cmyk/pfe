<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(['fr', 'ar'])]]);
        $request->session()->put('locale', $data['locale']);
        $request->user()?->forceFill(['locale' => $data['locale']])->saveQuietly();
        $previous = url()->previous();
        $parts = parse_url($previous);
        $host = parse_url(config('app.url'), PHP_URL_HOST);
        $path = is_array($parts) && ($parts['host'] ?? null) === $host ? ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '') : '/';

        return redirect(str_starts_with($path, '/') && ! str_starts_with($path, '//') ? $path : '/');
    }
}
