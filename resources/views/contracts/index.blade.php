<x-app-layout>
    @php
        $activeFilterCount = collect(['q', 'status'])
            ->filter(fn (string $key): bool => request()->filled($key))
            ->count();
    @endphp
    <div class="rf-page">
        <x-page-header
            title="Contrats de location"
            eyebrow="Activité locative"
            description="Suivez les contrats, leurs périodes et leur avancement dans votre périmètre autorisé."
        />

        <x-filter-panel title="Rechercher un contrat" :active-count="$activeFilterCount" :result-count="$contracts->total()">
            @if ($activeFilterCount > 0)
                <x-slot:tags>
                    @if (request()->filled('q'))
                        <a class="rf-filter-tag" href="{{ route('contracts.index', request()->except(['q', 'page'])) }}">Recherche : {{ request('q') }} <span aria-hidden="true">×</span><span class="sr-only">Retirer la recherche</span></a>
                    @endif
                    @if (request()->filled('status'))
                        <a class="rf-filter-tag" href="{{ route('contracts.index', request()->except(['status', 'page'])) }}">État <span aria-hidden="true">×</span><span class="sr-only">Retirer le filtre état</span></a>
                    @endif
                </x-slot:tags>
            @endif

            <form method="GET" class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,16rem)_auto] sm:items-end" data-loading-form>
                <div>
                    <x-input-label for="contract-search" value="Numéro de contrat" />
                    <input id="contract-search" name="q" value="{{ request('q') }}" placeholder="Ex. CTR-2026-000001" class="mt-1 w-full">
                </div>
                <div>
                    <x-input-label for="contract-status" value="État" />
                    <select id="contract-status" name="status" class="mt-1 w-full">
                        <option value="">Tous les états</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ App\Support\Ui\UiLabel::get($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <x-submit-button label="Appliquer" loading-label="Recherche…" />
                    @if ($activeFilterCount > 0)<a href="{{ route('contracts.index') }}" class="rf-button-secondary"><x-icon name="reset" size="xs" />Réinitialiser</a>@endif
                </div>
            </form>
        </x-filter-panel>

        <x-result-count :paginator="$contracts" />
        <x-responsive-table label="Liste des contrats de location">
            <table>
                <thead><tr><th>Contrat</th><th>Client</th><th>Véhicule</th><th>État</th><th>Période</th></tr></thead>
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
                        <tr><td colspan="5"><x-empty-state title="Aucun contrat" description="Aucun contrat ne correspond aux filtres sélectionnés." /></td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer>{{ $contracts->links() }}</x-slot:footer>
        </x-responsive-table>
    </div>
</x-app-layout>
