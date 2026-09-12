<x-app-layout>
    @php
        $activeFilterCount = collect(['agency_id', 'category_id', 'status'])
            ->filter(fn (string $key): bool => request()->filled($key))
            ->count();
    @endphp
    <div class="rf-page">
        <x-page-header :title="__('Véhicules')" :eyebrow="__('Parc automobile')" :description="__('Retrouvez la flotte autorisée, son affectation et son état opérationnel.')">
            <x-slot:actions>
                <a class="rf-button-secondary" href="{{ route('vehicle-categories.index') }}">{{ __('Catégories') }}</a>
                @can('create', App\Models\Vehicle::class)
                    <a class="rf-button-primary" href="{{ route('vehicles.create') }}"><x-icon name="add" size="xs" />{{ __('Nouveau véhicule') }}</a>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <x-filter-panel :title="__('Filtrer la flotte')" :active-count="$activeFilterCount" :result-count="$vehicles->total()">
            @if ($activeFilterCount > 0)
                <x-slot:tags>
                    @if (request()->filled('agency_id'))
                        <a class="rf-filter-tag" href="{{ route('vehicles.index', request()->except(['agency_id', 'page'])) }}">{{ __('Agence') }} <span aria-hidden="true">{{ __('×') }}</span><span class="sr-only">{{ __('Retirer le filtre agence') }}</span></a>
                    @endif
                    @if (request()->filled('category_id'))
                        <a class="rf-filter-tag" href="{{ route('vehicles.index', request()->except(['category_id', 'page'])) }}">{{ __('Catégorie') }} <span aria-hidden="true">{{ __('×') }}</span><span class="sr-only">{{ __('Retirer le filtre catégorie') }}</span></a>
                    @endif
                    @if (request()->filled('status'))
                        <a class="rf-filter-tag" href="{{ route('vehicles.index', request()->except(['status', 'page'])) }}">{{ __('État') }} <span aria-hidden="true">{{ __('×') }}</span><span class="sr-only">{{ __('Retirer le filtre état') }}</span></a>
                    @endif
                </x-slot:tags>
            @endif

            <form method="GET" class="rf-filter-grid" data-loading-form>
                <div>
                    <x-input-label for="vehicle-agency" :value="__('Agence')" />
                    <select id="vehicle-agency" name="agency_id" class="mt-1 w-full">
                        <option value="">{{ __('Toutes les agences') }}</option>
                        @foreach ($agencies as $agency)
                            <option value="{{ $agency->id }}" @selected(request('agency_id') == $agency->id)>{{ $agency->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="vehicle-category" :value="__('Catégorie')" />
                    <select id="vehicle-category" name="category_id" class="mt-1 w-full">
                        <option value="">{{ __('Toutes les catégories') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected(request('category_id') == $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="vehicle-status" :value="__('État')" />
                    <select id="vehicle-status" name="status" class="mt-1 w-full">
                        <option value="">{{ __('Tous les états') }}</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ App\Support\Ui\UiLabel::get($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <x-submit-button class="flex-1" :label="__('Appliquer')" loading-label="{{ __('Filtrage…') }}" />
                    @if ($activeFilterCount > 0)<a href="{{ route('vehicles.index') }}" class="rf-button-secondary"><x-icon name="reset" size="xs" />{{ __('Réinitialiser') }}</a>@endif
                </div>
            </form>
        </x-filter-panel>

        <x-result-count :paginator="$vehicles" />
        <x-responsive-table :label="__('Liste des véhicules')">
            <table>
                <thead><tr><th>{{ __('Immatriculation') }}</th><th>{{ __('Véhicule') }}</th><th>{{ __('Agence') }}</th><th>{{ __('État') }}</th></tr></thead>
                <tbody>
                    @forelse ($vehicles as $vehicle)
                        <tr>
                            <td><a class="font-semibold text-belkhir-space-blue hover:text-belkhir-space-blue-hover" href="{{ route('vehicles.show', $vehicle) }}">{{ $vehicle->registration_number }}</a></td>
                            <td>{{ $vehicle->brand }} {{ $vehicle->model }}</td>
                            <td>{{ $vehicle->agency->name }}</td>
                            <td><x-status-badge :value="$vehicle->operational_status" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><x-empty-state :title="__('Aucun véhicule')" :description="__('Aucun véhicule ne correspond aux filtres sélectionnés.')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer>{{ $vehicles->links() }}</x-slot:footer>
        </x-responsive-table>
    </div>
</x-app-layout>
