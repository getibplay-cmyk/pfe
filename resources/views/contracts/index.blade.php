<x-app-layout>
    @php
        $activeFilterCount = collect(['q', 'status'])
            ->filter(fn (string $key): bool => request()->filled($key))
            ->count();
    @endphp
    <div class="rf-page">
        <x-page-header
            :title="__('Contrats de location')"
            :eyebrow="__('Activité locative')"
            :description="__('Suivez les contrats, leurs périodes et leur avancement dans votre périmètre autorisé.')"
        />

        <x-filter-panel :title="__('Rechercher un contrat')" :active-count="$activeFilterCount" :result-count="$contracts->total()">
            @if ($activeFilterCount > 0)
                <x-slot:tags>
                    @if (request()->filled('q'))
                        <a class="rf-filter-tag" href="{{ route('contracts.index', request()->except(['q', 'page'])) }}">{{ __('Recherche :') }} {{ request('q') }} <span aria-hidden="true">{{ __('×') }}</span><span class="sr-only">{{ __('Retirer la recherche') }}</span></a>
                    @endif
                    @if (request()->filled('status'))
                        <a class="rf-filter-tag" href="{{ route('contracts.index', request()->except(['status', 'page'])) }}">{{ __('État') }} <span aria-hidden="true">{{ __('×') }}</span><span class="sr-only">{{ __('Retirer le filtre état') }}</span></a>
                    @endif
                </x-slot:tags>
            @endif

            <form method="GET" class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,16rem)_auto] sm:items-end" data-loading-form>
                <div>
                    <x-input-label for="contract-search" :value="__('Numéro de contrat')" />
                    <input id="contract-search" name="q" value="{{ request('q') }}" placeholder="{{ __('Ex. CTR-2026-000001') }}" class="mt-1 w-full">
                </div>
                <div>
                    <x-input-label for="contract-status" :value="__('État')" />
                    <select id="contract-status" name="status" class="mt-1 w-full">
                        <option value="">{{ __('Tous les états') }}</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ App\Support\Ui\UiLabel::get($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <x-submit-button :label="__('Appliquer')" loading-label="{{ __('Recherche…') }}" />
                    @if ($activeFilterCount > 0)<a href="{{ route('contracts.index') }}" class="rf-button-secondary"><x-icon name="reset" size="xs" />{{ __('Réinitialiser') }}</a>@endif
                </div>
            </form>
        </x-filter-panel>

        <x-result-count :paginator="$contracts" />
        <x-responsive-table :label="__('Liste des contrats de location')">
            <table>
                <thead><tr><th>{{ __('Contrat') }}</th><th>{{ __('Client') }}</th><th>{{ __('Véhicule') }}</th><th>{{ __('État') }}</th><th>{{ __('Période') }}</th></tr></thead>
                <tbody>
                    @forelse ($contracts as $contract)
                        <tr>
                            <td><a class="font-semibold text-belkhir-space-blue hover:text-belkhir-space-blue-hover" href="{{ route('contracts.show', $contract) }}">{{ $contract->contract_number }}</a></td>
                            <td>{{ $contract->customer->displayName() }}</td>
                            <td>{{ $contract->vehicle->registration_number }}</td>
                            <td><x-status-badge :value="$contract->status" /></td>
                            <td class="whitespace-nowrap">{{ App\Support\Ui\UiLabel::dateTime($contract->expected_start_at) }} — {{ App\Support\Ui\UiLabel::dateTime($contract->expected_return_at) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-empty-state :title="__('Aucun contrat')" :description="__('Aucun contrat ne correspond aux filtres sélectionnés.')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer>{{ $contracts->links() }}</x-slot:footer>
        </x-responsive-table>
    </div>
</x-app-layout>
