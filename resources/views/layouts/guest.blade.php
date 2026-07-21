<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php($authTitle = match (true) { request()->routeIs('login') => 'Connexion', request()->routeIs('password.request') => 'Mot de passe oublié', request()->routeIs('password.reset') => 'Réinitialiser le mot de passe', request()->routeIs('password.confirm') => 'Confirmer le mot de passe', request()->routeIs('verification.notice') => 'Vérifier l’adresse e-mail', default => 'Accès sécurisé' })
    <title>{{ $authTitle }} — RentFleet</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased">
    <a href="#contenu" class="rf-skip-link">Aller au formulaire</a>
    <main id="contenu" class="grid min-h-screen lg:grid-cols-[minmax(0,1fr)_minmax(28rem,0.82fr)]">
        <section class="relative hidden overflow-hidden bg-slate-950 p-10 text-white lg:flex lg:flex-col lg:justify-between xl:p-16" aria-label="Présentation de RentFleet">
            <x-brand-logo surface="dark" />
            <div class="max-w-xl">
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-300">SaaS B2B multitenant</p>
                <h2 class="mt-4 text-4xl font-bold leading-tight tracking-tight xl:text-5xl">Pilotez votre activité de location avec clarté.</h2>
                <p class="mt-5 max-w-lg text-base leading-7 text-slate-300">RentFleet réunit flotte, réservations, contrats, finance, maintenance et assurance dans un espace sécurisé adapté à chaque rôle.</p>
                <ul class="mt-8 grid gap-3 text-sm text-slate-300 sm:grid-cols-2">
                    <li class="flex gap-2"><span aria-hidden="true" class="text-fleet-500">✓</span> Périmètre tenant et agence</li>
                    <li class="flex gap-2"><span aria-hidden="true" class="text-fleet-500">✓</span> Autorisations par rôle</li>
                    <li class="flex gap-2"><span aria-hidden="true" class="text-fleet-500">✓</span> Historique et audit</li>
                    <li class="flex gap-2"><span aria-hidden="true" class="text-fleet-500">✓</span> Données privées protégées</li>
                </ul>
            </div>
            <p class="text-xs text-slate-500">Accès réservé aux organisations clientes et à leurs collaborateurs autorisés.</p>
        </section>
        <section class="flex items-center justify-center bg-slate-50 px-4 py-10 sm:px-8 lg:px-12">
            <div class="w-full max-w-md">
                <x-brand-logo class="mb-8 lg:hidden" />
                <div class="rf-panel p-6 sm:p-8">{{ $slot }}</div>
                <p class="mt-6 text-center text-xs leading-5 text-slate-500">Pas encore de compte ? L’inscription publique est désactivée. Contactez l’administrateur de votre organisation.</p>
            </div>
        </section>
    </main>
</body>
</html>
