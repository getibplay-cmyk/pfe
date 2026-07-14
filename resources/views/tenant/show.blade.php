<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-6">
        <div><p class="text-sm text-slate-500">Tenant courant</p><h1 class="text-3xl font-bold">{{ $tenant->name }}</h1></div>
        <dl class="grid gap-4 rounded-xl bg-white p-6 shadow-sm sm:grid-cols-2">
            <div><dt class="text-sm text-slate-500">Raison sociale</dt><dd>{{ $tenant->legal_name ?: 'Non renseignée' }}</dd></div>
            <div><dt class="text-sm text-slate-500">Statut</dt><dd>{{ $tenant->status->value }}</dd></div>
            <div><dt class="text-sm text-slate-500">E-mail</dt><dd>{{ $tenant->email ?: 'Non renseigné' }}</dd></div>
            <div><dt class="text-sm text-slate-500">Téléphone</dt><dd>{{ $tenant->phone ?: 'Non renseigné' }}</dd></div>
        </dl>
    </div>
</x-app-layout>
