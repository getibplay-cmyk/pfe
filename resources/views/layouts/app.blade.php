<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'RentFleet') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-100 font-sans text-slate-900 antialiased">
<div x-data="{ mobileMenu: false }" class="min-h-screen lg:flex">
    <aside class="hidden w-72 shrink-0 flex-col bg-slate-950 px-5 py-6 text-white lg:flex">
        <a href="{{ auth()->user()->is_platform_admin ? route('platform.dashboard') : route('dashboard') }}" class="rounded-lg px-2 text-2xl font-bold tracking-tight focus-visible:ring-offset-slate-950">RentFleet</a>
        <nav aria-label="Navigation principale" class="mt-8 flex-1 space-y-6 overflow-y-auto">
            @foreach ($navigationSections as $section)
                <section>
                    <h2 class="px-3 text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $section['label'] }}</h2>
                    <div class="mt-2 space-y-1">
                        @foreach ($section['items'] as $item)<x-navigation-item :item="$item" surface="desktop" />@endforeach
                    </div>
                </section>
            @endforeach
        </nav>
        <div class="mt-6 border-t border-white/10 pt-4 text-sm">
            <p class="truncate font-medium">{{ auth()->user()->name }}</p>
            <p class="truncate text-xs text-slate-400">{{ auth()->user()->email }}</p>
        </div>
    </aside>

    <div class="min-w-0 flex-1">
        <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:px-6">
            <div class="flex items-center justify-between gap-4">
                <div class="flex min-w-0 items-center gap-3">
                    <button type="button" @click="mobileMenu = true" class="rounded-lg border border-slate-200 p-2 text-slate-700 lg:hidden" aria-label="Ouvrir le menu" :aria-expanded="mobileMenu">
                        <svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <div class="min-w-0">
                        <p class="truncate font-semibold">{{ auth()->user()->tenant?->name ?? 'Administration plateforme' }}</p>
                        <p class="truncate text-xs text-slate-500">{{ auth()->user()->agency?->name ?? App\Support\Ui\UiLabel::get(auth()->user()->role?->slug ?? 'platform-admin') }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2 text-sm">
                    <a href="{{ route('profile.edit') }}" class="hidden rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-100 sm:inline-flex">Mon profil</a>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-100">Déconnexion</button></form>
                </div>
            </div>
        </header>

        <div x-cloak x-show="mobileMenu" class="fixed inset-0 z-40 lg:hidden" @keydown.escape.window="mobileMenu = false">
            <button type="button" aria-label="Fermer le menu" class="absolute inset-0 bg-slate-950/50" @click="mobileMenu = false"></button>
            <aside x-show="mobileMenu" x-transition class="relative h-full w-[min(88vw,22rem)] overflow-y-auto bg-white p-5 shadow-xl">
                <div class="flex items-center justify-between"><span class="text-xl font-bold">RentFleet</span><button type="button" @click="mobileMenu = false" class="rounded-lg p-2" aria-label="Fermer le menu">✕</button></div>
                <nav aria-label="Navigation mobile" class="mt-6 space-y-6">
                    @foreach ($navigationSections as $section)
                        <section>
                            <h2 class="px-3 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $section['label'] }}</h2>
                            <div class="mt-2 space-y-1">
                                @foreach ($section['items'] as $item)<x-navigation-item :item="$item" surface="mobile" />@endforeach
                            </div>
                        </section>
                    @endforeach
                </nav>
                <div class="mt-8 border-t pt-4"><a href="{{ route('profile.edit') }}" class="block rounded-lg px-3 py-2 font-medium text-slate-700 hover:bg-slate-100">Mon profil</a></div>
            </aside>
        </div>

        @if (session('status'))
            <div role="status" class="mx-4 mt-5 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900 sm:mx-6">{{ session('status') }}</div>
        @endif
        <main id="contenu" class="p-4 sm:p-6">{{ $slot }}</main>
    </div>
</div>
</body>
</html>
