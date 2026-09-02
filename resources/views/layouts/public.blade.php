<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ config('brand.description') }} — plateforme SaaS sécurisée pour les professionnels de la location automobile.">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} — {{ config('brand.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-belkhir-space-canvas font-sans antialiased">
    <x-belkhir-space-loading />
    <a href="#contenu" class="rf-skip-link">Aller au contenu principal</a>
    <header class="sticky top-0 z-40 border-b border-white/10 bg-belkhir-space-ink/95 text-white shadow-lg shadow-slate-950/5 backdrop-blur">
        <nav class="mx-auto flex min-h-16 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8" aria-label="Navigation publique">
            <a href="{{ route('home') }}" class="rounded-lg"><x-brand-logo surface="dark" /></a>
            <div class="hidden items-center gap-1 md:flex">
                <a href="{{ route('home') }}" class="rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('home') ? 'bg-white/10 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">Accueil</a>
                <a href="{{ route('pricing') }}" class="rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('pricing') ? 'bg-white/10 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">Tarifs</a>
                <a href="{{ route('subscription.public') }}" class="rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('subscription.public') ? 'bg-white/10 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">Abonnement</a>
            </div>
            <a href="{{ auth()->check() ? (auth()->user()->is_platform_admin ? route('platform.dashboard') : route('dashboard')) : route('login') }}" class="rf-button border-white/25 bg-white/10 text-white hover:bg-white/20">
                <x-icon :name="auth()->check() ? 'launch' : 'login'" size="xs" />{{ auth()->check() ? 'Mon espace' : 'Se connecter' }}
            </a>
        </nav>
        <nav class="mx-auto flex max-w-7xl gap-1 overflow-x-auto border-t border-white/10 px-4 py-2 md:hidden" aria-label="Navigation publique mobile">
            <a href="{{ route('home') }}" class="shrink-0 rounded-lg px-3 py-2 text-sm font-semibold text-slate-200">Accueil</a>
            <a href="{{ route('pricing') }}" class="shrink-0 rounded-lg px-3 py-2 text-sm font-semibold text-slate-200">Tarifs</a>
            <a href="{{ route('subscription.public') }}" class="shrink-0 rounded-lg px-3 py-2 text-sm font-semibold text-slate-200">Abonnement</a>
        </nav>
    </header>
    <main id="contenu" tabindex="-1">{{ $slot }}</main>
    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto grid max-w-7xl gap-6 px-4 py-10 sm:px-6 md:grid-cols-2 md:items-end lg:px-8">
            <div><x-brand-logo /><p class="mt-3 max-w-md text-sm leading-6 text-slate-600">{{ config('brand.description') }} pour les entreprises de location de véhicules.</p></div>
            <div class="text-sm text-slate-600 md:text-right"><p>© {{ now()->year }} {{ config('brand.name') }}.</p><p class="mt-1">Accès sur invitation — aucune inscription publique.</p></div>
        </div>
    </footer>
</body>
</html>
