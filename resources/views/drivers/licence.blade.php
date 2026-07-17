<x-app-layout>
    <div class="mx-auto max-w-xl rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
        <h1 class="text-2xl font-bold">Permis sensible</h1>
        <p class="mt-4">Conducteur : <strong>{{ $driver->first_name }} {{ $driver->last_name }}</strong></p>
        <p class="mt-3">Numéro complet : <strong>{{ $licence }}</strong></p>
        <p class="mt-4 text-sm text-slate-500">Cette consultation est autorisée, non mise en cache et journalisée.</p>
    </div>
</x-app-layout>
