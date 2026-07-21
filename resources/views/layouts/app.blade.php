<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle }} — RentFleet</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased">
@php
    $user = auth()->user();
    $home = $user->is_platform_admin ? route('platform.dashboard') : route('dashboard');
    $roleLabel = App\Support\Ui\UiLabel::get($user->is_platform_admin ? 'platform-admin' : $user->role?->slug);
    $statusMessage = match (session('status')) {
        'profile-updated' => 'Profil enregistré.',
        'password-updated' => 'Mot de passe mis à jour.',
        default => session('status'),
    };
@endphp
<a href="#contenu" class="rf-skip-link">Aller au contenu principal</a>
<div x-data="appShell" data-component="app-shell" class="min-h-screen lg:flex">
    <aside class="hidden w-72 shrink-0 flex-col bg-slate-950 px-5 py-6 text-white lg:flex" aria-label="Barre latérale">
        <a href="{{ $home }}" class="rounded-lg px-2" aria-label="RentFleet — accueil">
            <x-brand-logo surface="dark" />
        </a>
        <nav aria-label="Navigation principale" class="mt-8 flex-1 space-y-6 overflow-y-auto pe-1">
            @foreach ($navigationSections as $section)
                <section>
                    <h2 class="px-3 text-[0.68rem] font-bold uppercase tracking-[0.14em] text-slate-400">{{ $section['label'] }}</h2>
                    <div class="mt-2 space-y-1">
                        @foreach ($section['items'] as $item)<x-navigation-item :item="$item" surface="desktop" />@endforeach
                    </div>
                </section>
            @endforeach
        </nav>
        <div class="mt-6 rounded-xl border border-white/10 bg-white/5 p-4 text-sm">
            <p class="truncate font-semibold">{{ $user->name }}</p>
            <p class="mt-1 truncate text-xs text-slate-400">{{ $roleLabel }}</p>
            @if ($user->agency)<p class="mt-1 truncate text-xs text-slate-400">{{ $user->agency->name }}</p>@endif
        </div>
    </aside>

    <div class="min-w-0 flex-1">
        <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:px-6" aria-label="En-tête de l’application">
            <div class="flex items-center justify-between gap-4">
                <div class="flex min-w-0 items-center gap-3">
                    <button x-ref="menuButton" type="button" @click="openMenu($el)" class="rounded-lg border border-slate-200 p-2 text-slate-700 hover:bg-slate-50 lg:hidden" aria-label="Ouvrir le menu principal" :aria-expanded="mobileMenu.toString()" aria-controls="navigation-mobile">
                        <svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg>
                    </button>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-slate-950">{{ $pageTitle }}</p>
                        <p class="truncate text-xs text-slate-500">{{ $user->tenant?->name ?? 'Administration plateforme' }}@if($user->agency) · {{ $user->agency->name }}@endif</p>
                    </div>
                </div>
                <div class="relative" x-data="{ open: false, close(returnFocus = false) { this.open = false; if (returnFocus) this.$nextTick(() => this.$refs.userMenuButton.focus()) } }" @click.outside="close()" @keydown.escape.window="if (open) close(true)">
                    <button x-ref="userMenuButton" type="button" @click="open = ! open" class="flex min-h-10 items-center gap-3 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-left hover:bg-slate-50" :aria-expanded="open.toString()" aria-haspopup="menu" aria-controls="menu-utilisateur">
                        <span aria-hidden="true" class="flex h-7 w-7 items-center justify-center rounded-full bg-brand-100 text-xs font-bold text-brand-800">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                        <span class="hidden min-w-0 sm:block"><span class="block max-w-40 truncate text-xs font-semibold text-slate-900">{{ $user->name }}</span><span class="block max-w-40 truncate text-[0.68rem] text-slate-500">{{ $roleLabel }}</span></span>
                        <svg aria-hidden="true" class="h-4 w-4 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path d="m5.5 7.5 4.5 4.5 4.5-4.5" /></svg>
                    </button>
                    <div id="menu-utilisateur" x-cloak x-show="open" x-transition role="menu" aria-label="Menu utilisateur" class="absolute end-0 mt-2 w-52 rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl">
                        <a role="menuitem" href="{{ route('profile.edit') }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Mon profil</a>
                        <form method="POST" action="{{ route('logout') }}">@csrf<button role="menuitem" type="submit" class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-slate-700 hover:bg-slate-100">Déconnexion</button></form>
                    </div>
                </div>
            </div>
        </header>

        <div x-cloak x-show="mobileMenu" id="navigation-mobile" class="fixed inset-0 z-50 lg:hidden" @keydown.escape.window="if (mobileMenu) closeMenu()">
            <button type="button" aria-label="Fermer le menu" class="absolute inset-0 bg-slate-950/60" @click="closeMenu()"></button>
            <div x-show="mobileMenu" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full" class="h-full">
                <x-mobile-navigation :sections="$navigationSections" :user="$user" />
            </div>
        </div>

        @if ($statusMessage)<x-flash-message class="mx-4 mt-5 sm:mx-6" :message="$statusMessage" />@endif
        @if (session('error'))<x-flash-message type="error" class="mx-4 mt-5 sm:mx-6" :message="session('error')" />@endif
        <main id="contenu" tabindex="-1" class="p-4 sm:p-6 lg:p-8">{{ $slot }}</main>
    </div>
</div>
</body>
</html>
