@php($activeFilters = collect([request('q'), request('status')])->filter(fn ($value) => filled($value))->count())

<x-app-layout>
    <div class="rf-page max-w-6xl">
        <x-page-header
            title="Agences"
            eyebrow="Organisation"
            description="Consultez les sites rattachés à votre entreprise et leur état d’activité."
        >
            <x-slot:actions>
                @can('create', App\Models\Agency::class)
                    <x-link-button variant="primary" href="{{ route('agencies.create') }}">
                        <x-icon name="add" size="sm" />
                        Nouvelle agence
                    </x-link-button>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <x-filter-panel id="agency-filters" title="Rechercher une agence" :active-count="$activeFilters" :result-count="$agencies->total()">
            <form method="GET" action="{{ route('agencies.index') }}" class="grid gap-3 md:grid-cols-[minmax(0,1.5fr)_minmax(12rem,0.8fr)_auto]" data-loading-form>
                <div>
                    <x-input-label for="agency-search" value="Nom ou code" />
                    <div class="relative mt-1">
                        <span class="pointer-events-none absolute inset-y-0 start-0 flex w-11 items-center justify-center text-belkhir-space-muted" aria-hidden="true"><x-icon name="search" size="sm" /></span>
                        <input id="agency-search" name="q" value="{{ request('q') }}" class="w-full ps-11" autocomplete="off">
                    </div>
                </div>
                <div>
                    <x-input-label for="agency-status" value="Statut" />
                    <select id="agency-status" name="status" class="mt-1 w-full">
                        <option value="">Tous les statuts</option>
                        <option value="active" @selected(request('status') === 'active')>Actives</option>
                        <option value="inactive" @selected(request('status') === 'inactive')>Inactives</option>
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <x-submit-button label="Appliquer" loading-label="Filtrage…" />
                    @if ($activeFilters > 0)
                        <x-link-button href="{{ route('agencies.index') }}" class="px-3" aria-label="Réinitialiser les filtres">
                            <x-icon name="reset" size="sm" />
                            <span class="hidden sm:inline">Réinitialiser</span>
                        </x-link-button>
                    @endif
                </div>
            </form>
        </x-filter-panel>

        <x-result-count :paginator="$agencies" />

        <x-responsive-table label="Agences">
            <table>
                <thead><tr><th>Code</th><th>Nom</th><th>Statut</th><th class="text-right"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($agencies as $agency)
                        <tr>
                            <td><span class="rounded-lg bg-belkhir-space-canvas px-2.5 py-1 font-mono text-xs font-semibold text-belkhir-space-text">{{ $agency->code }}</span></td>
                            <td class="font-semibold text-belkhir-space-text">{{ $agency->name }}</td>
                            <td><x-status-badge :value="$agency->is_active ? 'active' : 'inactive'" /></td>
                            <td><div class="flex justify-end gap-2">
                                <x-icon-button icon="view" :label="'Consulter l’agence '.$agency->name" :href="route('agencies.show', $agency)" />
                                @can('update', $agency)
                                    <x-icon-button icon="edit" :label="'Modifier l’agence '.$agency->name" :href="route('agencies.edit', $agency)" />
                                @endcan
                            </div></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-4"><x-empty-state title="Aucune agence trouvée" description="Aucune agence ne correspond aux filtres sélectionnés." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-responsive-table>

        {{ $agencies->links() }}
    </div>
</x-app-layout>
