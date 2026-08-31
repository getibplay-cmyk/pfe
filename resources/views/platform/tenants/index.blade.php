<x-app-layout>
    @php
        $activeFilterCount = collect(['q', 'status'])
            ->filter(fn (string $key): bool => request()->filled($key))
            ->count();
    @endphp
    <div class="rf-page">
        <x-page-header title="Entreprises clientes" eyebrow="Administration SaaS" description="Structure, abonnement et accès aux assistances de chaque entreprise.">
            <x-slot:actions><a href="{{ route('platform.tenants.create') }}" class="rf-button-primary"><x-icon name="add" size="xs" />Nouvelle entreprise cliente</a></x-slot:actions>
        </x-page-header>

        <x-filter-panel title="Rechercher et filtrer" :active-count="$activeFilterCount" :result-count="$tenants->total()">
            @if ($activeFilterCount > 0)
                <x-slot:tags>
                    @if (request()->filled('q'))<a class="rf-filter-tag" href="{{ route('platform.tenants.index', request()->except(['q', 'page'])) }}">Recherche : {{ request('q') }} <span aria-hidden="true">×</span><span class="sr-only">Retirer la recherche</span></a>@endif
                    @if (request()->filled('status'))<a class="rf-filter-tag" href="{{ route('platform.tenants.index', request()->except(['status', 'page'])) }}">État <span aria-hidden="true">×</span><span class="sr-only">Retirer le filtre état</span></a>@endif
                </x-slot:tags>
            @endif
            <form method="GET" class="grid gap-3 md:grid-cols-3 md:items-end" data-loading-form>
                <div><x-input-label for="platform-tenant-search" value="Recherche" /><input id="platform-tenant-search" name="q" value="{{ request('q') }}" placeholder="Nom, identifiant ou raison sociale" class="mt-1 w-full"></div>
                <div><x-input-label for="platform-tenant-status" value="État" /><select id="platform-tenant-status" name="status" class="mt-1 w-full"><option value="">Tous les états</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ App\Support\Ui\UiLabel::get($status) }}</option>@endforeach</select></div>
                <div class="flex items-end gap-2"><x-submit-button label="Appliquer" loading-label="Recherche…" />@if($activeFilterCount > 0)<a href="{{ route('platform.tenants.index') }}" class="rf-button-secondary"><x-icon name="reset" size="xs" />Réinitialiser</a>@endif</div>
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
                            <td>{{ App\Support\Ui\BusinessNumber::integer($tenant->agencies_count) }}</td><td>{{ App\Support\Ui\BusinessNumber::integer($tenant->users_count) }}</td><td>{{ App\Support\Ui\BusinessNumber::integer($tenant->vehicles_count) }}</td><td>{{ App\Support\Ui\BusinessNumber::integer($tenant->enabled_capabilities_count) }} / 6</td>
                            <td>{{ App\Support\Ui\UiLabel::date($tenant->created_at) }}</td>
                            <td class="text-right"><x-icon-button icon="view" :label="'Consulter l’entreprise '.$tenant->name" :href="route('platform.tenants.show', $tenant)" /></td>
                        </tr>
                    @empty<tr><td colspan="9"><x-empty-state title="Aucune entreprise ne correspond aux filtres" /></td></tr>@endforelse
                </tbody>
            </table>
            <x-slot:footer>{{ $tenants->links() }}</x-slot:footer>
        </x-responsive-table>
    </div>
</x-app-layout>
