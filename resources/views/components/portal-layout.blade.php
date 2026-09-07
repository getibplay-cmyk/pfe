@props(['title' => 'Mon espace locataire'])
<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="robots" content="noindex, nofollow"><title>{{ $title }} — {{ config('brand.name') }}</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body class="bg-belkhir-space-canvas font-sans text-slate-900 antialiased">
    <a href="#contenu" class="rf-skip-link">Aller au contenu</a>
    <header class="border-b bg-white px-4 py-4 print:hidden"><div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4"><x-brand-logo />@if(request()->attributes->has('portal_customer'))<form method="POST" action="{{ route('portal.logout') }}">@csrf<x-secondary-button type="submit">Quitter mon espace</x-secondary-button></form>@endif</div></header>
    <main id="contenu" class="mx-auto max-w-6xl space-y-6 px-4 py-6 sm:px-6"><h1 class="text-2xl font-bold">{{ $title }}</h1><x-form-errors />@if(session('status'))<p role="status" class="rounded-xl bg-emerald-50 p-4 text-emerald-900">{{ session('status') }}</p>@endif{{ $slot }}</main>
</body></html>
