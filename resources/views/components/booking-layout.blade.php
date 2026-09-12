@props(['profile', 'title'])
<!DOCTYPE html><html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="robots" content="noindex, nofollow"><title>{{ $title }} — {{ $profile->public_name }}</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body class="bg-belkhir-space-canvas font-sans text-slate-900 antialiased">
    <a href="#contenu" class="rf-skip-link">{{ __('Aller au contenu') }}</a>
    <header class="border-b border-white/10 bg-belkhir-space-ink px-4 py-5 text-white"><div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4"><a class="text-xl font-bold" href="{{ route('booking.catalog', $profile->slug) }}">{{ $profile->public_name }}</a><x-locale-switcher /></div></header>
    <main id="contenu" class="mx-auto max-w-6xl space-y-6 px-4 py-8"><h1 class="text-3xl font-bold">{{ $title }}</h1><x-form-errors />{{ $slot }}</main>
    <footer class="border-t bg-white p-6 text-center text-sm text-slate-600"><p>{{ $profile->public_name }}</p>@if($profile->public_phone)<p class="mt-2" dir="ltr">{{ $profile->public_phone }}</p>@endif @if($profile->public_email)<p class="mt-2" dir="ltr">{{ $profile->public_email }}</p>@endif<p class="mt-3">{{ __('Service proposé avec') }} {{ config('brand.name') }}</p></footer>
</body></html>
