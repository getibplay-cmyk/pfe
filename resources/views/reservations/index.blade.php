<x-app-layout>
    <div class="rf-page">
        <x-page-header title="Réservations" eyebrow="Locations" description="Consultez les demandes, leur période, leur affectation et leur état dans votre périmètre autorisé.">
            <x-slot:actions>
                @if (auth()->user()->hasPermission('reservation.export'))<a href="#export" class="rf-button-secondary">Exporter en CSV</a>@endif
                @can('create', App\Models\Reservation::class)<a href="{{ route('reservations.create') }}" class="rf-button-primary">Nouvelle réservation</a>@endcan
            </x-slot:actions>
        </x-page-header>
        <x-filter-panel>
            <form class="rf-filter-grid">
                <div><x-input-label for="reservation-q" value="Numéro" /><input id="reservation-q" name="q" value="{{ request('q') }}" placeholder="Ex. RES-2026-000001" class="mt-1 w-full"></div>
                <div><x-input-label for="reservation-agency" value="Agence" /><select id="reservation-agency" name="agency_id" class="mt-1 w-full"><option value="">Toutes les agences autorisées</option>@foreach ($agencies as $agency)<option value="{{ $agency->id }}" @selected(request('agency_id') == $agency->id)>{{ $agency->name }}</option>@endforeach</select></div>
                <div><x-input-label for="reservation-status" value="Statut" /><select id="reservation-status" name="status" class="mt-1 w-full"><option value="">Tous les statuts</option>@foreach ($statuses as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>@endforeach</select></div>
                <div class="flex items-end gap-2"><x-primary-button class="flex-1">Filtrer</x-primary-button>@if(request()->hasAny(['q','agency_id','status']))<a href="{{ route('reservations.index') }}" class="rf-button-secondary">Effacer</a>@endif</div>
            </form>
        </x-filter-panel>
        @if ($demandForecastAssistant !== null)
            <x-section-card>
                <div
                    x-data='reservationDemandForecast(@json($demandForecastAssistant))'
                    class="space-y-5"
                >
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-950">Prévision de la demande — 7 prochains jours</h2>
                            <p class="mt-1 text-sm text-slate-600">
                                Agence : <span class="font-medium text-slate-800" x-text="scope.agency || 'À sélectionner'"></span>
                            </p>
                            <p x-cloak x-show="generatedAt" class="mt-1 text-sm text-slate-500">
                                Générée le <span x-text="formatGeneratedAt(generatedAt)"></span>
                            </p>
                        </div>
                        @if ($demandForecastAssistant['canRequest'])
                            <button
                                type="button"
                                class="rf-button-secondary inline-flex items-center justify-center gap-2"
                                x-on:click="refresh"
                                x-bind:disabled="busy || !available || !agencyId"
                                x-bind:aria-busy="busy.toString()"
                            >
                                <svg x-cloak x-show="busy" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z"></path>
                                </svg>
                                Actualiser les prévisions
                            </button>
                        @endif
                    </div>

                    <x-loading-state x-cloak x-show="busy" message="Préparation de la prévision…" />
                    <p x-show="! busy" class="text-sm text-slate-700" role="status" aria-live="polite" x-text="message">{{ $demandForecastAssistant['initial']['message'] }}</p>

                    <div x-show="forecasts.length === 7" class="grid gap-5 xl:grid-cols-[minmax(0,1.6fr)_minmax(18rem,1fr)]">
                        <div class="rf-chart-surface">
                            <canvas
                                x-ref="forecastChart"
                                role="img"
                                aria-label="Courbe de la demande prévue pour les sept prochains jours"
                            ></canvas>
                        </div>
                        <x-responsive-table label="Tableau des prévisions de demande">
                            <table>
                                <caption class="sr-only">Demande prévue, par date, pour les sept prochains jours</caption>
                                <thead><tr><th>Date</th><th class="text-right">Demande prévue</th></tr></thead>
                                <tbody>
                                    <template x-for="forecast in forecasts" x-bind:key="forecast.date">
                                        <tr>
                                            <td x-text="formatDate(forecast.date)"></td>
                                            <td class="text-right font-medium" x-text="formatDemand(forecast.predictedDemand)"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </x-responsive-table>
                    </div>

                    <p class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-950">
                        Ces prévisions sont une aide à la planification. Elles ne modifient aucune réservation et restent soumises à votre décision.
                    </p>
                </div>
            </x-section-card>
        @endif
        @if (auth()->user()->hasPermission('reservation.export'))
            <x-filter-panel id="export" title="Exporter les réservations">
                <form method="GET" action="{{ route('reservations.export') }}" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div><x-input-label for="export-from" value="Du" required /><input id="export-from" type="date" name="date_from" value="{{ now()->startOfMonth()->toDateString() }}" required class="mt-1 w-full"></div>
                    <div><x-input-label for="export-to" value="Au" required /><input id="export-to" type="date" name="date_to" value="{{ today()->toDateString() }}" required class="mt-1 w-full"></div>
                    <div><x-input-label for="export-agency" value="Agence" /><select id="export-agency" name="agency_id" class="mt-1 w-full"><option value="">Toutes les agences autorisées</option>@foreach ($agencies as $agency)<option value="{{ $agency->id }}">{{ $agency->name }}</option>@endforeach</select></div>
                    <div><x-input-label for="export-status" value="Statut" /><select id="export-status" name="status" class="mt-1 w-full"><option value="">Tous</option>@foreach ($statuses as $status)<option value="{{ $status->value }}">{{ $status->label() }}</option>@endforeach</select></div>
                    <div><x-input-label for="export-category" value="Catégorie" /><select id="export-category" name="vehicle_category_id" class="mt-1 w-full"><option value="">Toutes</option>@foreach ($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></div>
                    <div><x-input-label for="export-vehicle" value="Véhicule" /><select id="export-vehicle" name="vehicle_id" class="mt-1 w-full"><option value="">Tous</option>@foreach ($vehicles as $vehicle)<option value="{{ $vehicle->id }}">{{ $vehicle->registration_number }}</option>@endforeach</select></div>
                    <div class="flex items-end md:col-span-2"><x-primary-button class="w-full md:w-auto">Télécharger le fichier CSV</x-primary-button></div>
                </form>
            </x-filter-panel>
        @endif
        <x-result-count :paginator="$reservations" />
        <x-responsive-table label="Liste des réservations">
            <table><thead><tr><th>Numéro</th><th>Client</th><th>Agence</th><th>Période</th><th>Véhicule</th><th>Statut</th><th class="text-right">Total</th></tr></thead><tbody>
                @forelse ($reservations as $reservation)
                    <tr><td><a class="font-semibold text-brand-700 hover:text-brand-900" href="{{ route('reservations.show', $reservation) }}">{{ $reservation->reservation_number }}</a></td><td>{{ $reservation->customer->displayName() }}</td><td>{{ $reservation->agency->name }}</td><td class="whitespace-nowrap">{{ App\Support\Ui\UiLabel::dateTime($reservation->starts_at) }}<br><span class="text-slate-500">au {{ App\Support\Ui\UiLabel::dateTime($reservation->ends_at) }}</span></td><td>{{ $reservation->vehicle?->registration_number ?? 'À affecter' }}</td><td><x-status-badge :value="$reservation->status" /></td><td class="whitespace-nowrap text-right font-medium">{{ App\Support\Ui\UiLabel::money($reservation->total_amount, $reservation->currency) }}</td></tr>
                @empty<tr><td colspan="7"><x-empty-state title="Aucune réservation" description="Aucune réservation ne correspond aux filtres sélectionnés." /></td></tr>@endforelse
            </tbody></table>
            <x-slot:footer>{{ $reservations->links() }}</x-slot:footer>
        </x-responsive-table>
    </div>
</x-app-layout>
