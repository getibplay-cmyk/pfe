<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-8">
        <div><p class="text-sm text-slate-500">Vue d’ensemble</p><h1 class="text-3xl font-bold">Bienvenue sur RentFleet</h1></div>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach(['Véhicules disponibles', 'Réservations du jour', 'Départs attendus', 'Alertes critiques'] as $label)
                <div class="rounded-xl bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">{{ $label }}</p><p class="mt-3 text-3xl font-bold">0</p></div>
            @endforeach
        </div>
        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl bg-white p-6 shadow-sm"><h2 class="font-semibold">Activité récente</h2><p class="mt-8 text-sm text-slate-500">Aucune activité récente.</p></section>
            <section class="rounded-xl bg-white p-6 shadow-sm"><h2 class="font-semibold">Alertes</h2><p class="mt-8 text-sm text-slate-500">Aucune alerte.</p></section>
        </div>
    </div>
</x-app-layout>
