<x-app-layout>
    @php
        $activeFilterCount = collect(['q', 'agency_id', 'status'])
            ->filter(fn (string $key): bool => request()->filled($key))
            ->count();
    @endphp
    <div class="rf-page">
        <x-page-header :title="__('Réservations')" :eyebrow="__('Locations')" :description="__('Consultez les demandes, leur période, leur affectation et leur état dans votre périmètre autorisé.')">
            <x-slot:actions>
                @if (auth()->user()->hasPermission('reservation.export'))<a href="#export" class="rf-button-secondary"><x-icon name="download" size="xs" />{{ __('Exporter en CSV') }}</a>@endif
                @can('create', App\Models\Reservation::class)<a href="{{ route('reservations.create') }}" class="rf-button-primary"><x-icon name="add" size="xs" />{{ __('Nouvelle réservation') }}</a>@endcan
            </x-slot:actions>
        </x-page-header>
        <x-filter-panel :title="__('Filtrer les réservations')" :active-count="$activeFilterCount" :result-count="$reservations->total()">
            @if ($activeFilterCount > 0)
                <x-slot:tags>
                    @if (request()->filled('q'))<a class="rf-filter-tag" href="{{ route('reservations.index', request()->except(['q', 'page'])) }}">{{ __('Numéro :') }} {{ request('q') }} <span aria-hidden="true">{{ __('×') }}</span><span class="sr-only">{{ __('Retirer la recherche') }}</span></a>@endif
                    @if (request()->filled('agency_id'))<a class="rf-filter-tag" href="{{ route('reservations.index', request()->except(['agency_id', 'page'])) }}">{{ __('Agence') }} <span aria-hidden="true">{{ __('×') }}</span><span class="sr-only">{{ __('Retirer le filtre agence') }}</span></a>@endif
                    @if (request()->filled('status'))<a class="rf-filter-tag" href="{{ route('reservations.index', request()->except(['status', 'page'])) }}">{{ __('État') }} <span aria-hidden="true">{{ __('×') }}</span><span class="sr-only">{{ __('Retirer le filtre état') }}</span></a>@endif
                </x-slot:tags>
            @endif
            <form class="rf-filter-grid" data-loading-form>
                <div><x-input-label for="reservation-q" :value="__('Numéro')" /><input id="reservation-q" name="q" value="{{ request('q') }}" placeholder="{{ __('Ex. RES-2026-000001') }}" class="mt-1 w-full"></div>
                <div><x-input-label for="reservation-agency" :value="__('Agence')" /><select id="reservation-agency" name="agency_id" class="mt-1 w-full"><option value="">{{ __('Toutes les agences autorisées') }}</option>@foreach ($agencies as $agency)<option value="{{ $agency->id }}" @selected(request('agency_id') == $agency->id)>{{ $agency->name }}</option>@endforeach</select></div>
                <div><x-input-label for="reservation-status" :value="__('Statut')" /><select id="reservation-status" name="status" class="mt-1 w-full"><option value="">{{ __('Tous les statuts') }}</option>@foreach ($statuses as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>@endforeach</select></div>
                <div class="flex items-end gap-2"><x-submit-button class="flex-1" :label="__('Appliquer')" loading-label="{{ __('Filtrage…') }}" />@if($activeFilterCount > 0)<a href="{{ route('reservations.index') }}" class="rf-button-secondary"><x-icon name="reset" size="xs" />{{ __('Réinitialiser') }}</a>@endif</div>
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
                            <h2 class="text-lg font-semibold text-slate-950">{{ __('Prévision de la demande — 7 prochains jours') }}</h2>
                            <p class="mt-1 text-sm text-slate-600">
                                {{ __('Agence :') }} <span class="font-medium text-slate-800" x-text="scope.agency || 'À sélectionner'"></span>
                            </p>
                            <p x-cloak x-show="generatedAt" class="mt-1 text-sm text-slate-500">
                                {{ __('Générée le') }} <span x-text="formatGeneratedAt(generatedAt)"></span>
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
                                <x-spinner x-cloak x-show="busy" />
                                <x-icon name="refresh" size="xs" x-cloak x-show="!busy" />
                                <span x-text="busy ? 'Actualisation…' : 'Actualiser les prévisions'">{{ __('Actualiser les prévisions') }}</span>
                            </button>
                        @endif
                    </div>

                    <x-loading-state x-cloak x-show="busy" :message="__('Préparation de la prévision…')" />
                    <p x-show="! busy" class="text-sm text-slate-700" role="status" aria-live="polite" x-text="message">{{ $demandForecastAssistant['initial']['message'] }}</p>

                    <div x-show="forecasts.length === 7" class="grid gap-5 xl:grid-cols-[minmax(0,1.6fr)_minmax(18rem,1fr)]">
                        <div class="rf-chart-surface">
                            <canvas
                                x-ref="forecastChart"
                                role="img"
                                aria-label="{{ __('Courbe des véhicules à prévoir pour les sept prochains jours') }}"
                            ></canvas>
                        </div>
                        <x-responsive-table :label="__('Tableau des prévisions de demande')">
                            <table>
                                <caption class="sr-only">{{ __('Véhicules à prévoir, par date, pour les sept prochains jours') }}</caption>
                                <thead><tr><th>{{ __('Date') }}</th><th class="text-end">{{ __('Véhicules à prévoir') }}</th></tr></thead>
                                <tbody>
                                    <template x-for="forecast in forecasts" x-bind:key="forecast.date">
                                        <tr>
                                            <td x-text="formatDate(forecast.date)"></td>
                                            <td class="text-end font-medium" x-text="formatDemand(forecast.planningVehicleUnits)"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </x-responsive-table>
                    </div>

                    <p class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-950">
                        {{ __('Ces prévisions sont une aide à la planification. Elles ne modifient aucune réservation et restent soumises à votre décision.') }}
                    </p>
                </div>
            </x-section-card>
        @endif
        @if (auth()->user()->hasPermission('reservation.export'))
            <x-filter-panel id="export" :title="__('Exporter les réservations')">
                <form method="GET" action="{{ route('reservations.export') }}" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4" data-loading-form data-no-global-loading="true">
                    <div><x-input-label for="export-from" :value="__('Du')" required /><input id="export-from" type="date" name="date_from" value="{{ now()->startOfMonth()->toDateString() }}" required class="mt-1 w-full"></div>
                    <div><x-input-label for="export-to" :value="__('Au')" required /><input id="export-to" type="date" name="date_to" value="{{ today()->toDateString() }}" required class="mt-1 w-full"></div>
                    <div><x-input-label for="export-agency" :value="__('Agence')" /><select id="export-agency" name="agency_id" class="mt-1 w-full"><option value="">{{ __('Toutes les agences autorisées') }}</option>@foreach ($agencies as $agency)<option value="{{ $agency->id }}">{{ $agency->name }}</option>@endforeach</select></div>
                    <div><x-input-label for="export-status" :value="__('Statut')" /><select id="export-status" name="status" class="mt-1 w-full"><option value="">{{ __('Tous') }}</option>@foreach ($statuses as $status)<option value="{{ $status->value }}">{{ $status->label() }}</option>@endforeach</select></div>
                    <div><x-input-label for="export-category" :value="__('Catégorie')" /><select id="export-category" name="vehicle_category_id" class="mt-1 w-full"><option value="">{{ __('Toutes') }}</option>@foreach ($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></div>
                    <div><x-input-label for="export-vehicle" :value="__('Véhicule')" /><select id="export-vehicle" name="vehicle_id" class="mt-1 w-full"><option value="">{{ __('Tous') }}</option>@foreach ($vehicles as $vehicle)<option value="{{ $vehicle->id }}">{{ $vehicle->registration_number }}</option>@endforeach</select></div>
                    <div class="flex items-end md:col-span-2"><x-submit-button class="w-full md:w-auto" :label="__('Télécharger le fichier CSV')" loading-label="{{ __('Préparation du fichier…') }}" /></div>
                </form>
            </x-filter-panel>
        @endif
        <x-result-count :paginator="$reservations" />
        <x-responsive-table :label="__('Liste des réservations')">
            <table><thead><tr><th>{{ __('Numéro') }}</th><th>{{ __('Client') }}</th><th>{{ __('Agence') }}</th><th>{{ __('Période') }}</th><th>{{ __('Véhicule') }}</th><th>{{ __('Statut') }}</th><th class="text-end">{{ __('Total') }}</th></tr></thead><tbody>
                @forelse ($reservations as $reservation)
                    <tr><td><a class="font-semibold text-brand-700 hover:text-brand-900" href="{{ route('reservations.show', $reservation) }}">{{ $reservation->reservation_number }}</a></td><td>{{ $reservation->customer->displayName() }}</td><td>{{ $reservation->agency->name }}</td><td class="whitespace-nowrap">{{ App\Support\Ui\UiLabel::dateTime($reservation->starts_at) }}<br><span class="text-slate-500">{{ __('au') }} {{ App\Support\Ui\UiLabel::dateTime($reservation->ends_at) }}</span></td><td>{{ $reservation->vehicle?->registration_number ?? __('À affecter') }}</td><td><x-status-badge :value="$reservation->status" /></td><td class="whitespace-nowrap text-end font-medium">{{ App\Support\Ui\UiLabel::money($reservation->total_amount, $reservation->currency) }}</td></tr>
                @empty<tr><td colspan="7"><x-empty-state :title="__('Aucune réservation')" :description="__('Aucune réservation ne correspond aux filtres sélectionnés.')" /></td></tr>@endforelse
            </tbody></table>
            <x-slot:footer>{{ $reservations->links() }}</x-slot:footer>
        </x-responsive-table>
    </div>
</x-app-layout>
