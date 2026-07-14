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
<div class="min-h-screen md:flex">
    <aside class="hidden w-64 shrink-0 flex-col bg-slate-950 p-6 text-white md:flex">
        <a href="{{ auth()->user()->is_platform_admin ? route('platform.dashboard') : route('dashboard') }}" class="text-2xl font-bold">RentFleet</a>
        <nav class="mt-10 space-y-2 text-sm">
            @if(auth()->user()->is_platform_admin)
                <a class="block rounded-lg bg-white/10 px-4 py-3" href="{{ route('platform.dashboard') }}">Plateforme</a>
            @else
                <a class="block rounded-lg px-4 py-3 hover:bg-white/10" href="{{ route('dashboard') }}">Tableau de bord</a>
                <a class="block rounded-lg px-4 py-3 hover:bg-white/10" href="{{ route('tenant.show') }}">Entreprise</a>
                <a class="block rounded-lg px-4 py-3 hover:bg-white/10" href="{{ route('agencies.index') }}">Agences</a>
                <a class="block rounded-lg px-4 py-3 hover:bg-white/10" href="{{ route('users.index') }}">Utilisateurs</a>
                @can('viewAny', App\Models\Vehicle::class)<a class="block rounded-lg px-4 py-3 hover:bg-white/10" href="{{ route('vehicles.index') }}">Véhicules</a>@endcan
                @can('viewAny', App\Models\Customer::class)<a class="block rounded-lg px-4 py-3 hover:bg-white/10" href="{{ route('customers.index') }}">Clients</a>@endcan
                <a class="block rounded-lg px-4 py-3 hover:bg-white/10" href="{{ route('audit-logs.index') }}">Journal d’audit</a>
            @endif
        </nav>
    </aside>
    <div class="min-w-0 flex-1">
        <header class="border-b bg-white px-4 py-4 md:px-6">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="font-semibold">{{ auth()->user()->tenant?->name ?? 'Administration plateforme' }}</p>
                    <p class="text-xs text-slate-500">{{ auth()->user()->agency?->name ?? auth()->user()->role?->name }}</p>
                </div>
                <div class="flex items-center gap-4 text-sm">
                    <span class="hidden sm:inline">{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button class="text-slate-600 hover:text-slate-950">Déconnexion</button></form>
                </div>
            </div>
            @unless(auth()->user()->is_platform_admin)
                <nav class="mt-4 flex gap-3 overflow-x-auto text-sm md:hidden">
                    <a href="{{ route('dashboard') }}">Dashboard</a><a href="{{ route('tenant.show') }}">Entreprise</a><a href="{{ route('agencies.index') }}">Agences</a>@can('viewAny', App\Models\Vehicle::class)<a href="{{ route('vehicles.index') }}">Véhicules</a>@endcan @can('viewAny', App\Models\Customer::class)<a href="{{ route('customers.index') }}">Clients</a>@endcan
                </nav>
            @endunless
        </header>
        @if(session('status'))<div class="mx-6 mt-6 rounded-lg bg-emerald-100 p-3 text-sm text-emerald-900">{{ session('status') }}</div>@endif
        <main class="p-4 md:p-6">{{ $slot }}</main>
    </div>
</div>
</body>
</html>
