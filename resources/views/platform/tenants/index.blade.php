<x-app-layout>
    @php
        $activeFilterCount = collect(['q', 'status'])
            ->filter(fn (string $key): bool => request()->filled($key))
            ->count();
    @endphp
    <div class="rf-page">
        <x-page-header :title="__('Entreprises clientes')" :eyebrow="__('Administration SaaS')" :description="__('Structure, abonnement et accès aux assistances de chaque entreprise.')">
            <x-slot:actions><a href="{{ route('platform.onboarding-invitations.index') }}#nouvelle-invitation" class="rf-button-secondary"><x-icon name="add" size="xs" />{{ __('Inviter') }}</a><a href="{{ route('platform.tenants.create') }}" class="rf-button-primary"><x-icon name="add" size="xs" />{{ __('Nouvelle entreprise cliente') }}</a></x-slot:actions>
        </x-page-header>

        <x-filter-panel :title="__('Rechercher et filtrer')" :active-count="$activeFilterCount" :result-count="$tenants->total()">
            @if ($activeFilterCount > 0)
                <x-slot:tags>
                    @if (request()->filled('q'))<a class="rf-filter-tag" href="{{ route('platform.tenants.index', request()->except(['q', 'page'])) }}">{{ __('Recherche :') }} {{ request('q') }} <span aria-hidden="true">{{ __('×') }}</span><span class="sr-only">{{ __('Retirer la recherche') }}</span></a>@endif
                    @if (request()->filled('status'))<a class="rf-filter-tag" href="{{ route('platform.tenants.index', request()->except(['status', 'page'])) }}">{{ __('État') }} <span aria-hidden="true">{{ __('×') }}</span><span class="sr-only">{{ __('Retirer le filtre état') }}</span></a>@endif
                </x-slot:tags>
            @endif
            <form method="GET" class="grid gap-3 md:grid-cols-3 md:items-end" data-loading-form>
                <div><x-input-label for="platform-tenant-search" :value="__('Recherche')" /><input id="platform-tenant-search" name="q" value="{{ request('q') }}" placeholder="{{ __('Nom, identifiant ou raison sociale') }}" class="mt-1 w-full"></div>
                <div><x-input-label for="platform-tenant-status" :value="__('État')" /><select id="platform-tenant-status" name="status" class="mt-1 w-full"><option value="">{{ __('Tous les états') }}</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ App\Support\Ui\UiLabel::get($status) }}</option>@endforeach</select></div>
                <div class="flex items-end gap-2"><x-submit-button :label="__('Appliquer')" loading-label="{{ __('Recherche…') }}" />@if($activeFilterCount > 0)<a href="{{ route('platform.tenants.index') }}" class="rf-button-secondary"><x-icon name="reset" size="xs" />{{ __('Réinitialiser') }}</a>@endif</div>
            </form>
        </x-filter-panel>

        <x-result-count :paginator="$tenants" />
        <x-responsive-table :label="__('Liste des entreprises clientes')">
            <table class="rf-table">
                <thead><tr><th>{{ __('Entreprise') }}</th><th>{{ __('État') }}</th><th>{{ __('Abonnement courant') }}</th><th>{{ __('Agences') }}</th><th>{{ __('Utilisateurs') }}</th><th>{{ __('Véhicules') }}</th><th>{{ __('Assistances autorisées') }}</th><th>{{ __('Création') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></tr></thead>
                <tbody>
                    @forelse($tenants as $tenant)
                        <tr>
                            <td><strong>{{ $tenant->name }}</strong><span class="block text-slate-500">{{ $tenant->slug }}</span></td>
                            <td><x-status-badge :value="$tenant->status" /></td>
                            <td>@if($tenant->current_plan_name)<strong>{{ $tenant->current_plan_name }}</strong><span class="block text-slate-500">{{ match($tenant->current_subscription_status) {'trialing' => __('Période d’essai'), 'active' => __('Actif'), 'past_due' => __('Échéance dépassée'), 'suspended' => __('Suspendu'), default => '—'} }}</span>@else<span class="text-slate-500">{{ __('Aucun abonnement') }}</span>@endif</td>
                            <td>{{ App\Support\Ui\BusinessNumber::integer($tenant->agencies_count) }}</td><td>{{ App\Support\Ui\BusinessNumber::integer($tenant->users_count) }}</td><td>{{ App\Support\Ui\BusinessNumber::integer($tenant->vehicles_count) }}</td><td>{{ App\Support\Ui\BusinessNumber::integer($tenant->enabled_capabilities_count) }} / 6</td>
                            <td>{{ App\Support\Ui\UiLabel::date($tenant->created_at) }}</td>
                            <td class="text-end"><x-icon-button icon="view" :label="__('Consulter l’entreprise ').$tenant->name" :href="route('platform.tenants.show', $tenant)" /></td>
                        </tr>
                    @empty<tr><td colspan="9"><x-empty-state :title="__('Aucune entreprise ne correspond aux filtres')" /></td></tr>@endforelse
                </tbody>
            </table>
            <x-slot:footer>{{ $tenants->links() }}</x-slot:footer>
        </x-responsive-table>
    </div>
</x-app-layout>
