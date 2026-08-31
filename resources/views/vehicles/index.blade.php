<x-app-layout>
    @php
        $activeFilterCount = collect(['agency_id', 'category_id', 'status'])
            ->filter(fn (string $key): bool => request()->filled($key))
            ->count();
    @endphp
    <div class="rf-page">
        <x-page-header title="Véhicules" eyebrow="Parc automobile" description="Retrouvez la flotte autorisée, son affectation et son état opérationnel.">
            <x-slot:actions>
                <a class="rf-button-secondary" href="{{ route('vehicle-categories.index') }}">Catégories</a>
                @can('create', App\Models\Vehicle::class)
                    <a class="rf-button-primary" href="{{ route('vehicles.create') }}"><x-icon name="add" size="xs" />Nouveau véhicule</a>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <x-filter-panel title="Filtrer la flotte" :active-count="$activeFilterCount" :result-count="$vehicles->total()">
            @if ($activeFilterCount > 0)
                <x-slot:tags>
                    @if (request()->filled('agency_id'))
                        <a class="rf-filter-tag" href="{{ route('vehicles.index', request()->except(['agency_id', 'page'])) }}">Agence <span aria-hidden="true">×</span><span class="sr-only">Retirer le filtre agence</span></a>
                    @endif
                    @if (request()->filled('category_id'))
                        <a class="rf-filter-tag" href="{{ route('vehicles.index', request()->except(['category_id', 'page'])) }}">Catégorie <span aria-hidden="true">×</span><span class="sr-only">Retirer le filtre catégorie</span></a>
                    @endif
                    @if (request()->filled('status'))
                        <a class="rf-filter-tag" href="{{ route('vehicles.index', request()->except(['status', 'page'])) }}">État <span aria-hidden="true">×</span><span class="sr-only">Retirer le filtre état</span></a>
                    @endif
                </x-slot:tags>
            @endif

            <form method="GET" class="rf-filter-grid" data-loading-form>
                <div>
                    <x-input-label for="vehicle-agency" value="Agence" />
                    <select id="vehicle-agency" name="agency_id" class="mt-1 w-full">
                        <option value="">Toutes les agences</option>
                        @foreach ($agencies as $agency)
                            <option value="{{ $agency->id }}" @selected(request('agency_id') == $agency->id)>{{ $agency->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="vehicle-category" value="Catégorie" />
                    <select id="vehicle-category" name="category_id" class="mt-1 w-full">
                        <option value="">Toutes les catégories</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected(request('category_id') == $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="vehicle-status" value="État" />
                    <select id="vehicle-status" name="status" class="mt-1 w-full">
                        <option value="">Tous les états</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ App\Support\Ui\UiLabel::get($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <x-submit-button class="flex-1" label="Appliquer" loading-label="Filtrage…" />
                    @if ($activeFilterCount > 0)<a href="{{ route('vehicles.index') }}" class="rf-button-secondary"><x-icon name="reset" size="xs" />Réinitialiser</a>@endif
                </div>
            </form>
        </x-filter-panel>

        <x-result-count :paginator="$vehicles" />
        <x-responsive-table label="Liste des véhicules">
            <table>
                <thead><tr><th>Immatriculation</th><th>Véhicule</th><th>Agence</th><th>État</th></tr></thead>
                <tbody>
                    @forelse ($vehicles as $vehicle)
                        <tr>
                            <td><a class="font-semibold text-belkhir-space-blue hover:text-belkhir-space-blue-hover" href="{{ route('vehicles.show', $vehicle) }}">{{ $vehicle->registration_number }}</a></td>
                            <td>{{ $vehicle->brand }} {{ $vehicle->model }}</td>
                            <td>{{ $vehicle->agency->name }}</td>
                            <td><x-status-badge :value="$vehicle->operational_status" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><x-empty-state title="Aucun véhicule" description="Aucun véhicule ne correspond aux filtres sélectionnés." /></td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer>{{ $vehicles->links() }}</x-slot:footer>
        </x-responsive-table>
    </div>
</x-app-layout>
