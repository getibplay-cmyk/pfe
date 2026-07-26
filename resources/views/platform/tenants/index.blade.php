<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header title="Entreprises clientes" eyebrow="Administration SaaS">
            <x-slot:actions><a href="{{ route('platform.tenants.create') }}" class="rf-button-primary">Nouvelle entreprise cliente</a></x-slot:actions>
        </x-page-header>

        <form method="GET" class="grid gap-3 rounded-xl bg-white p-4 shadow-sm md:grid-cols-3">
            <label class="text-sm" for="platform-tenant-search">Recherche
                <input id="platform-tenant-search" name="q" value="{{ request('q') }}" placeholder="Nom, identifiant ou raison sociale" class="mt-1 w-full">
            </label>
            <label class="text-sm" for="platform-tenant-status">État
                <select id="platform-tenant-status" name="status" class="mt-1 w-full">
                    <option value="">Tous les états</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ App\Support\Ui\UiLabel::get($status) }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit" class="self-end rounded-lg bg-slate-800 px-4 py-2 text-white">Filtrer</button>
        </form>

        <x-result-count :paginator="$tenants" />
        <div class="overflow-x-auto rounded-xl bg-white shadow-sm" role="region" aria-label="Liste des entreprises clientes" tabindex="0">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left"><tr><th class="p-4">Entreprise cliente</th><th class="p-4">Raison sociale</th><th class="p-4">État</th><th class="p-4">Créée le</th><th class="p-4"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse($tenants as $tenant)
                        <tr class="border-t"><td class="p-4"><strong>{{ $tenant->name }}</strong><br><span class="text-slate-500">{{ $tenant->slug }}</span></td><td class="p-4">{{ $tenant->legal_name ?? '—' }}</td><td class="p-4"><x-status-badge :value="$tenant->status" /></td><td class="p-4">{{ App\Support\Ui\UiLabel::date($tenant->created_at) }}</td><td class="p-4 text-right"><a href="{{ route('platform.tenants.show', $tenant) }}" class="text-indigo-700">Consulter</a></td></tr>
                    @empty
                        <tr><td colspan="5" class="p-8 text-center text-slate-500">Aucune entreprise cliente ne correspond aux filtres.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $tenants->links() }}
    </div>
</x-app-layout>
