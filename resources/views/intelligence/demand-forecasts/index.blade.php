<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header
            title="Prévision de demande D+1 à D+7"
            eyebrow="Aide à la décision"
            description="Préparez une prévision sur sept jours, examinez les scénarios proposés et gardez la décision finale."
        />

        @if (session('status'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-900" role="status">
                {{ session('status') }}
            </div>
        @endif

        <div class="rounded-xl border p-4 text-sm leading-6 {{ $runtime['ready'] ? 'border-emerald-200 bg-emerald-50 text-emerald-950' : 'border-amber-200 bg-amber-50 text-amber-950' }}">
            @if ($runtime['ready'])
                <span class="font-semibold">Service de prévision disponible.</span>
                Les fichiers nécessaires sont vérifiés et le traitement peut être demandé depuis {{ config('brand.name') }}.
            @elseif (! $runtime['enabled'])
                <span class="font-semibold">Service de prévision non disponible.</span>
                L’import manuel reste disponible, mais aucune nouvelle prévision ne peut être lancée.
            @else
                <span class="font-semibold">Service d’analyse temporairement indisponible.</span>
                Contactez l’administrateur de la plateforme avant de relancer une prévision.
            @endif
        </div>

        <x-section-card title="Comment utiliser la prévision" description="Les résultats aident à préparer le planning et restent soumis à votre connaissance du terrain.">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-slate-200 p-4"><p class="text-slate-500">Période</p><p class="mt-2 font-semibold text-slate-950">Les 7 prochains jours</p></div>
                <div class="rounded-xl border border-slate-200 p-4"><p class="text-slate-500">Estimation centrale</p><p class="mt-2 font-semibold text-slate-950">Scénario le plus probable</p></div>
                <div class="rounded-xl border border-slate-200 p-4"><p class="text-slate-500">Scénario prudent</p><p class="mt-2 font-semibold text-slate-950">Marge pour anticiper un pic</p></div>
                <div class="rounded-xl border border-slate-200 p-4"><p class="text-slate-500">Décision</p><p class="mt-2 font-semibold text-slate-950">Toujours validée par un responsable</p></div>
            </div>
            <p class="mt-4 text-xs leading-5 text-slate-500">Comparez la prévision avec les réservations, les événements locaux et la disponibilité réelle avant d’adapter le planning.</p>
        </x-section-card>

        <x-section-card title="Données utilisées" description="Un historique agrégé par agence, sans identité client ni coordonnées.">
            <div class="grid gap-3 text-sm md:grid-cols-4">
                <div class="rounded-xl bg-slate-50 p-4">
                    <p class="font-medium text-slate-500">Activité mesurée</p>
                    <p class="mt-1 font-semibold text-slate-900">Départs réellement observés</p>
                    <p class="mt-1 text-xs text-slate-500">Comptés par jour et par agence</p>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <p class="font-medium text-slate-500">Dates manquantes</p>
                    <p class="mt-1 font-semibold text-slate-900">Remplies à zéro</p>
                    <p class="mt-1 text-xs text-slate-500">Pour conserver un calendrier continu</p>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <p class="font-medium text-slate-500">Historique minimal</p>
                    <p class="mt-1 font-semibold text-slate-900">{{ $contract['minimum_history_days'] }} jours</p>
                    <p class="mt-1 text-xs text-slate-500">Une période plus longue améliore le contexte</p>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <p class="font-medium text-slate-500">Protection des données</p>
                    <p class="mt-1 font-semibold text-slate-900">Données agrégées</p>
                    <p class="mt-1 text-xs text-slate-500">Aucune identité ni coordonnée exportée</p>
                </div>
            </div>
        </x-section-card>

        @if (auth()->user()->hasPermission('prediction.export'))
            <x-filter-panel title="Préparer l’historique de demande">
                <form method="GET" action="{{ route('intelligence.demand-history.export') }}" class="grid gap-4 md:grid-cols-4" data-loading-form data-no-global-loading="true">
                    <div>
                        <x-input-label for="demand-date-from" value="Du" />
                        <x-text-input id="demand-date-from" name="date_from" type="date" class="mt-1 block w-full" :value="$filters['date_from']" required />
                        <x-field-error :messages="$errors->get('date_from')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="demand-date-to" value="Au" />
                        <x-text-input id="demand-date-to" name="date_to" type="date" class="mt-1 block w-full" :value="$filters['date_to']" required />
                        <x-field-error :messages="$errors->get('date_to')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="demand-agency" value="Agence" />
                        <select id="demand-agency" name="agency_id" class="mt-1 block w-full rounded-lg border-slate-300" required>
                            @if ($agencies->count() > 1)<option value="">Choisir une agence</option>@endif
                            @foreach ($agencies as $agency)
                                <option value="{{ $agency->id }}" @selected(($filters['agency_id'] ?? null) == $agency->id)>{{ $agency->name }}</option>
                            @endforeach
                        </select>
                        <x-field-error :messages="$errors->get('agency_id')" class="mt-2" />
                    </div>
                    <div class="flex items-end">
                        <x-submit-button class="w-full justify-center" label="Générer et télécharger" loading-label="Génération…" :disabled="! $configured" />
                    </div>
                </form>
                @unless ($configured)
                    <p class="mt-3 text-sm text-amber-800">La préparation des données doit être configurée par l’administrateur.</p>
                @endunless
            </x-filter-panel>
        @endif

        <x-section-card title="Historiques disponibles" description="Sélectionnez un historique pour générer ou importer une prévision.">
            <x-responsive-table label="Historiques de demande" class="shadow-none">
                <table>
                    <thead>
                        <tr>
                            <th>Agence et période</th>
                            <th>Historique</th>
                            <th>Départs</th>
                            <th>Prévisions</th>
                            <th><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($historyRuns as $run)
                            @php
                                $execution = $run->executionRuns->first();
                                $executionIsActive = $execution && in_array($execution->status->value, ['queued', 'running'], true);
                            @endphp
                            <tr>
                                <td>
                                    <p class="font-medium text-slate-900">{{ $run->agency->name }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ $run->date_from->format('d/m/Y') }} → {{ $run->date_to->format('d/m/Y') }}</p>
                                </td>
                                <td>{{ App\Support\Ui\BusinessNumber::count($run->row_count, 'jour') }} continus</td>
                                <td>{{ App\Support\Ui\BusinessNumber::integer($run->observed_departures_count) }}</td>
                                <td>
                                    <p>{{ App\Support\Ui\BusinessNumber::integer($run->forecast_runs_count) }}</p>
                                    @if ($execution)
                                        <p class="mt-1"><x-status-badge :value="$execution->status->value" :label="$execution->status->label()" /></p>
                                        @if ($execution->failure_code)
                                            <p class="mt-1 text-xs text-rose-700">{{ $execution->failureLabel() }}</p>
                                        @endif
                                    @endif
                                </td>
                                <td class="text-right">
                                    <div class="flex flex-wrap justify-end gap-3">
                                        @can('view', $run)
                                            <x-icon-button icon="file" :label="'Consulter les informations de contrôle de '.$run->agency->name" :href="route('intelligence.demand-history.manifest', $run)" data-no-global-loading="true" />
                                            <x-icon-button icon="download" :label="'Télécharger le CSV de '.$run->agency->name" :href="route('intelligence.demand-history.download', $run)" data-no-global-loading="true" />
                                        @endcan
                                    </div>
                                    @can('importForecast', $run)
                                        @if ($runtime['ready'])
                                            <form method="POST" action="{{ route('intelligence.demand-forecast-executions.store', $run) }}" class="mt-3" data-loading-form>
                                                @csrf
                                                <x-submit-button
                                                    :label="$executionIsActive ? 'Prévision déjà en cours' : 'Générer la prévision'"
                                                    loading-label="Génération en cours…"
                                                    :disabled="$executionIsActive"
                                                />
                                            </form>
                                        @endif
                                        <details class="mt-3 text-left">
                                            <summary class="cursor-pointer text-xs font-medium text-slate-600">Import manuel de secours</summary>
                                            <form method="POST" action="{{ route('intelligence.demand-forecasts.store', $run) }}" enctype="multipart/form-data" class="mt-2 space-y-3" data-loading-form>
                                                @csrf
                                                <x-file-input :id="'forecast-batch-'.$run->id" name="forecast_batch" label="Fichier de prévision" accept="application/json,.json" formats="JSON" required :errors="$errors->get('forecast_batch')" />
                                                <x-submit-button label="Importer" loading-label="Import en cours…" />
                                            </form>
                                        </details>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="p-10 text-center text-slate-500">Aucun historique de demande n’a encore été exporté.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-responsive-table>
            <x-field-error :messages="$errors->get('forecast_batch')" class="mt-3" />
        </x-section-card>

        @php
            $factorLabels = [
                'lag_1_at_cutoff' => 'demande au dernier jour connu',
                'lag_2_at_cutoff' => 'demande deux jours avant la cible',
                'lag_3_at_cutoff' => 'demande trois jours avant la cible',
                'lag_7_at_cutoff' => 'demande récente à sept jours',
                'lag_14_at_cutoff' => 'demande récente à quatorze jours',
                'lag_28_at_cutoff' => 'demande récente à vingt-huit jours',
                'seasonal_lag_target_minus_7' => 'saisonnalité hebdomadaire',
                'rolling_mean_7_at_cutoff' => 'moyenne mobile sur 7 jours',
                'rolling_mean_28_at_cutoff' => 'moyenne mobile sur 28 jours',
                'rolling_median_7_at_cutoff' => 'médiane mobile sur 7 jours',
                'rolling_median_28_at_cutoff' => 'médiane mobile sur 28 jours',
                'rolling_std_7_at_cutoff' => 'volatilité sur 7 jours',
                'rolling_std_28_at_cutoff' => 'volatilité sur 28 jours',
                'target_is_weekend' => 'effet week-end',
            ];
        @endphp

        <x-section-card title="Prévisions disponibles" description="Comparez le scénario central, le scénario prudent et la fourchette probable avant d’organiser la flotte.">
            <div class="space-y-6">
                @forelse ($forecastRuns as $run)
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        @if ($run->executionRun)
                            <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-950">
                                <strong>Prévision générée depuis {{ config('brand.name') }}.</strong>
                                Les données ont été vérifiées avant le calcul.
                            </div>
                        @endif
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">Prévision consultative · {{ $run->agency->name }}</p>
                                <h2 class="mt-1 text-lg font-semibold text-slate-950">Prévision arrêtée au {{ $run->as_of_date->format('d/m/Y') }}</h2>
                            </div>
                            <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-900">À vérifier</span>
                        </div>

                        <div class="mt-4 rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm leading-6 text-blue-950">
                            Ces valeurs aident à planifier la flotte. Comparez-les avec les réservations, les événements locaux et le contexte de l’agence avant toute décision.
                        </div>

                        <div class="mt-4 overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                                        <th class="px-3 py-2">Horizon</th>
                                        <th class="px-3 py-2">Date</th>
                                        <th class="px-3 py-2">Demande moyenne</th>
                                        <th class="px-3 py-2">Estimation centrale</th>
                                        <th class="px-3 py-2">Scénario prudent</th>
                                        <th class="px-3 py-2">Fourchette probable</th>
                                        <th class="px-3 py-2">Éléments pris en compte</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach ($run->forecasts as $forecast)
                                        <tr>
                                            <td class="whitespace-nowrap px-3 py-3 font-semibold">D+{{ $forecast->horizon }}</td>
                                            <td class="whitespace-nowrap px-3 py-3">{{ $forecast->target_date->format('d/m/Y') }}</td>
                                            <td class="whitespace-nowrap px-3 py-3">{{ App\Support\Ui\BusinessNumber::scientificDecimal($forecast->conditional_mean, 2) }}</td>
                                            <td class="whitespace-nowrap px-3 py-3 font-semibold text-indigo-800">{{ App\Support\Ui\BusinessNumber::scientificDecimal($forecast->p50, 2) }}</td>
                                            <td class="whitespace-nowrap px-3 py-3 font-semibold text-amber-800">{{ App\Support\Ui\BusinessNumber::scientificDecimal($forecast->p90, 2) }}</td>
                                            <td class="whitespace-nowrap px-3 py-3">{{ App\Support\Ui\BusinessNumber::scientificDecimal($forecast->p05, 2) }} – {{ App\Support\Ui\BusinessNumber::scientificDecimal($forecast->p95, 2) }}</td>
                                            <td class="min-w-72 px-3 py-3">
                                                <ul class="space-y-1">
                                                    @foreach ($forecast->explanations as $factor)
                                                        <li>
                                                            <span class="font-medium">{{ $factorLabels[$factor['feature']] ?? $factor['feature'] }}</span>
                                                            <span class="text-slate-500">· {{ $factor['direction'] === 'increase' ? 'hausse' : ($factor['direction'] === 'decrease' ? 'baisse' : 'neutre') }}</span>
                                                            <span class="text-slate-500">· {{ App\Support\Ui\BusinessNumber::signedScientificDecimal($factor['prediction_delta'], 2) }} départ(s)</span>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </article>
                @empty
                    <x-empty-state
                        title="Aucune prévision disponible"
                        description="Préparez un historique puis lancez une prévision."
                    />
                @endforelse
            </div>
            <div class="mt-5">{{ $forecastRuns->links() }}</div>
        </x-section-card>
    </div>
</x-app-layout>
