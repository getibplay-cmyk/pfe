<x-app-layout>
    <div class="rf-page">
        <x-page-header title="Entreprises clientes" eyebrow="Administration SaaS" description="Structure, abonnement et accès aux assistances de chaque entreprise.">
            <x-slot:actions><a href="{{ route('platform.tenants.create') }}" class="rf-button-primary">Nouvelle entreprise cliente</a></x-slot:actions>
        </x-page-header>

        <x-filter-panel title="Rechercher et filtrer">
            <form method="GET" class="grid gap-3 md:grid-cols-3 md:items-end">
                <div><x-input-label for="platform-tenant-search" value="Recherche" /><input id="platform-tenant-search" name="q" value="{{ request('q') }}" placeholder="Nom, identifiant ou raison sociale" class="mt-1 w-full"></div>
                <div><x-input-label for="platform-tenant-status" value="État" /><select id="platform-tenant-status" name="status" class="mt-1 w-full"><option value="">Tous les états</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ App\Support\Ui\UiLabel::get($status) }}</option>@endforeach</select></div>
                <button type="submit" class="rf-button-primary">Filtrer</button>
            </form>
        </x-filter-panel>

        <x-result-count :paginator="$tenants" />
        <x-responsive-table label="Liste des entreprises clientes">
            <table class="rf-table">
                <thead><tr><th>Entreprise</th><th>État</th><th>Abonnement courant</th><th>Agences</th><th>Utilisateurs</th><th>Véhicules</th><th>Assistances autorisées</th><th>Création</th><th><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse($tenants as $tenant)
                        <tr>
                            <td><strong>{{ $tenant->name }}</strong><span class="block text-slate-500">{{ $tenant->slug }}</span></td>
                            <td><x-status-badge :value="$tenant->status" /></td>
                            <td>@if($tenant->current_plan_name)<strong>{{ $tenant->current_plan_name }}</strong><span class="block text-slate-500">{{ match($tenant->current_subscription_status) {'trialing' => 'Période d’essai', 'active' => 'Actif', 'past_due' => 'Échéance dépassée', 'suspended' => 'Suspendu', default => '—'} }}</span>@else<span class="text-slate-500">Aucun abonnement</span>@endif</td>
                            <td>{{ $tenant->agencies_count }}</td><td>{{ $tenant->users_count }}</td><td>{{ $tenant->vehicles_count }}</td><td>{{ $tenant->enabled_capabilities_count }} / 6</td>
                            <td>{{ App\Support\Ui\UiLabel::date($tenant->created_at) }}</td>
                            <td class="text-right"><a href="{{ route('platform.tenants.show', $tenant) }}" class="rf-button-link">Consulter</a></td>
                        </tr>
                    @empty<tr><td colspan="9"><x-empty-state title="Aucune entreprise ne correspond aux filtres" /></td></tr>@endforelse
                </tbody>
            </table>
            <x-slot:footer>{{ $tenants->links() }}</x-slot:footer>
        </x-responsive-table>
    </div>
</x-app-layout>
