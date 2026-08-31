<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php($authTitle = match (true) { request()->routeIs('login') => 'Connexion', request()->routeIs('password.request') => 'Mot de passe oublié', request()->routeIs('password.reset') => 'Réinitialiser le mot de passe', request()->routeIs('password.confirm') => 'Confirmer le mot de passe', request()->routeIs('password.change-required') => 'Choisir un mot de passe', request()->routeIs('verification.notice') => 'Vérifier l’adresse e-mail', default => 'Accès sécurisé' })
    <title>{{ $authTitle }} — {{ config('brand.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-belkhir-space-canvas font-sans antialiased">
    <x-belkhir-space-loading />
    <a href="#contenu" class="rf-skip-link">Aller au formulaire</a>
    <main id="contenu" class="flex min-h-screen min-h-[100dvh] flex-col lg:grid lg:grid-cols-[minmax(24rem,0.92fr)_minmax(30rem,1.08fr)]">
        <section class="relative flex min-h-[12.5rem] shrink-0 overflow-hidden bg-belkhir-space-ink px-5 py-6 text-white sm:px-8 lg:min-h-screen lg:flex-col lg:justify-between lg:p-12 xl:p-16" aria-label="Présentation de {{ config('brand.name') }}">
            <div data-belkhir-space-route-motif aria-hidden="true" class="pointer-events-none absolute inset-0 overflow-hidden opacity-70">
                <span class="absolute -right-20 -top-24 h-72 w-72 rounded-full border border-brand-400/30"></span>
                <span class="absolute -right-4 top-16 h-40 w-40 rounded-full border border-brand-300/20"></span>
                <span class="absolute -left-16 bottom-10 h-28 w-[34rem] -rotate-6 rounded-[999px] border border-brand-400/20"></span>
                <span class="absolute bottom-16 left-[42%] h-3 w-3 rounded-full bg-belkhir-space-orange shadow-[0_0_0_7px_rgba(194,65,12,0.18)]"></span>
                <span class="absolute right-[18%] top-[42%] hidden h-2.5 w-2.5 rounded-full bg-brand-400 shadow-[0_0_0_6px_rgba(59,130,246,0.14)] lg:block"></span>
            </div>

            <div class="relative z-10 flex w-full flex-col justify-between gap-6 lg:h-full">
                <x-brand-logo surface="dark" />

                <div class="max-w-xl lg:my-auto">
                    <p class="hidden border-s-4 border-belkhir-space-orange ps-3 text-xs font-bold uppercase tracking-[0.18em] text-brand-300 sm:block">SaaS B2B multi-entreprises</p>
                    <h2 class="mt-2 max-w-lg text-2xl font-bold leading-tight tracking-tight sm:text-3xl lg:mt-5 lg:text-4xl xl:text-5xl">Pilotez votre activité de location en toute clarté.</h2>
                    <p class="mt-5 hidden max-w-lg text-base leading-7 text-slate-300 lg:block">{{ config('brand.name') }} rassemble les opérations essentielles de votre agence dans un espace sécurisé et adapté à chaque rôle.</p>

                    <ul class="mt-8 hidden gap-3 lg:grid" aria-label="Fonctions principales">
                        @foreach (['Réservations et contrats', 'Parc automobile', 'Analyses et prévisions'] as $capability)
                            <li class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm font-medium text-slate-100">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-belkhir-space-orange/20 text-belkhir-space-orange-soft" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="m5 12 4 4L19 6" /></svg>
                                </span>
                                {{ $capability }}
                            </li>
                        @endforeach
                    </ul>
                </div>

                <p class="hidden text-xs leading-5 text-slate-400 lg:block">Accès réservé aux organisations clientes et à leurs collaborateurs autorisés.</p>
            </div>
        </section>

        <section class="flex flex-1 items-start justify-center bg-belkhir-space-canvas px-4 py-8 sm:px-8 sm:py-12 lg:items-center lg:px-12 xl:px-20">
            <div class="w-full max-w-lg">
                <div class="rf-panel overflow-hidden shadow-[0_24px_70px_-38px_rgba(11,18,32,0.45)]">
                    <div class="flex h-1" aria-hidden="true">
                        <span class="flex-1 bg-belkhir-space-blue"></span>
                        <span class="w-20 bg-belkhir-space-orange"></span>
                    </div>
                    <div class="p-6 sm:p-8 lg:p-10">{{ $slot }}</div>
                </div>
                <p class="mx-auto mt-5 max-w-md text-center text-xs leading-5 text-belkhir-space-muted">Pas encore de compte ? L’inscription publique est désactivée. Contactez l’administrateur de votre organisation.</p>
            </div>
        </section>
    </main>
</body>
</html>
