<x-app-layout>
    <div class="rf-page" x-data='fleetReallocationPlanning(@json($assistant))'>
        <x-page-header
            title="Planification de réallocation de flotte"
            eyebrow="Flotte"
            description="Comparez la demande prévue et les véhicules disponibles dans vos agences pour préparer les sept prochains jours."
        >
            <x-slot:actions>
                <button
                    type="button"
                    class="rf-button-primary inline-flex items-center gap-2"
                    x-on:click="calculate"
                    x-bind:disabled="busy || !ready"
                    x-bind:aria-busy="busy.toString()"
                >
                    <x-spinner x-cloak x-show="busy" />
                    <span x-text="busy ? 'Calcul en cours…' : 'Calculer un plan'">Calculer un plan</span>
                </button>
            </x-slot:actions>
        </x-page-header>

        <x-section-card title="État de préparation">
            <div class="flex flex-wrap items-center gap-3">
                <x-status-badge :value="$assistant['ready'] ? 'ready' : 'unavailable'" />
                <p class="text-sm text-slate-700">{{ $assistant['readinessMessage'] }}</p>
            </div>
            @if ($assistant['referenceDate'])
                <p class="mt-3 text-sm text-slate-600">Date de référence : <strong>{{ $assistant['referenceDate'] }}</strong></p>
            @endif
        </x-section-card>

        <p class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-950" role="status" aria-live="polite" x-text="message"></p>

        <template x-if="generatedAt">
            <p class="text-sm text-slate-500">Plan généré le <span x-text="formatGeneratedAt(generatedAt)"></span></p>
        </template>

        <x-section-card title="Besoins par agence" description="Les disponibilités sont recalculées côté serveur à partir des blocages métier.">
            <template x-if="agencies.length === 0">
                <x-empty-state title="Aucun plan calculé" description="Lancez le calcul lorsque les données sont prêtes." />
            </template>
            <div x-show="agencies.length > 0">
                <x-responsive-table label="Besoins de planification par agence et par date">
                    <table>
                        <thead><tr><th>Date</th><th>Agence</th><th class="text-right">Moyenne des départs prévus</th><th class="text-right">Besoin de planification arrondi à l’unité supérieure</th><th class="text-right">Véhicules disponibles</th><th class="text-right">Surplus transférable</th><th class="text-right">Besoin non couvert</th></tr></thead>
                        <tbody>
                            <template x-for="row in agencies" x-bind:key="`${row.date}-${row.name}`">
                                <tr><td x-text="formatDate(row.date)"></td><td x-text="row.name"></td><td class="text-right" x-text="formatAverage(row.predicted_departures)"></td><td class="text-right" x-text="formatBusinessInteger(row.planning_vehicle_units)"></td><td class="text-right" x-text="formatBusinessInteger(row.available_vehicle_units)"></td><td class="text-right" x-text="formatBusinessInteger(row.transferable_surplus)"></td><td class="text-right" x-text="formatBusinessInteger(row.uncovered_need)"></td></tr>
                            </template>
                        </tbody>
                    </table>
                </x-responsive-table>
            </div>
        </x-section-card>

        <x-section-card title="Transferts proposés">
            <template x-if="status === 'succeeded' && recommendations.length === 0">
                <x-empty-state title="Aucun transfert proposé" description="Le détail du résultat est indiqué ci-dessus." />
            </template>
            <div x-show="recommendations.length > 0">
                <x-responsive-table label="Transferts de véhicules proposés">
                    <table>
                        <thead><tr><th>Date</th><th>Agence de départ</th><th>Agence de destination</th><th class="text-right">Véhicules</th><th class="text-right">Distance</th></tr></thead>
                        <tbody>
                            <template x-for="row in recommendations" x-bind:key="`${row.date}-${row.from_agency}-${row.to_agency}`">
                                <tr><td x-text="formatDate(row.date)"></td><td x-text="row.from_agency"></td><td x-text="row.to_agency"></td><td class="text-right" x-text="formatBusinessInteger(row.vehicle_units)"></td><td class="text-right" x-text="formatDistance(row.distance_km)"></td></tr>
                            </template>
                        </tbody>
                    </table>
                </x-responsive-table>
            </div>
        </x-section-card>

        <p class="rounded-xl border border-orange-200 bg-orange-50 px-4 py-3 text-sm font-medium text-orange-950">
            Ce plan est consultatif. Aucun véhicule n’est déplacé automatiquement.
        </p>
    </div>
</x-app-layout>
