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

        <x-section-card title="Niveau de preuve du modèle" :description="'Les performances ci-dessous viennent du benchmark public Munich ; elles ne mesurent pas encore '.config('brand.name').'.'">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Modèle gelé</p>
                    <p class="mt-2 break-words font-mono text-sm font-semibold text-slate-900">{{ $contract['model_name'] }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $contract['model_version'] }} · {{ $contract['compute'] }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">WAPE public</p>
                    <p class="mt-2 text-2xl font-semibold text-slate-950">{{ App\Support\Ui\BusinessNumber::confidence($contract['public_wape']) }}</p>
                    <p class="mt-1 text-xs text-slate-500">Plus faible = meilleur</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Complément du WAPE</p>
                    <p class="mt-2 text-2xl font-semibold text-indigo-800">{{ App\Support\Ui\BusinessNumber::complementConfidence($contract['public_wape']) }}</p>
                    <p class="mt-1 text-xs text-slate-500">Indicateur lisible, pas une accuracy de classification</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">MASE public</p>
                    <p class="mt-2 text-2xl font-semibold text-slate-950">{{ App\Support\Ui\BusinessNumber::scientificDecimal($contract['public_mase'], 4, 4) }}</p>
                    <p class="mt-1 text-xs text-slate-500">Meilleur que la référence naïve si &lt; 1</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Couverture P05–P95</p>
                    <p class="mt-2 text-2xl font-semibold text-slate-950">{{ App\Support\Ui\BusinessNumber::confidence($contract['public_interval_coverage']) }}</p>
                    <p class="mt-1 text-xs text-slate-500">Benchmark public, nominal 90 %</p>
                </div>
            </div>
            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
                <span class="font-semibold">Statut scientifique :</span>
                validation locale non disponible tant qu’un historique réel suffisant n’a pas été constitué et évalué sur un holdout temporel fermé. Les sorties sont des aides à la planification, jamais des décisions métier.
            </div>
        </x-section-card>

        <x-section-card :title="'Prétraitement compatible '.config('brand.name')" description="Une série agrégée par agence, sans identité client ni coordonnées.">
            <div class="grid gap-3 text-sm md:grid-cols-4">
                <div class="rounded-xl bg-slate-50 p-4">
                    <p class="font-medium text-slate-500">Cible</p>
                    <p class="mt-1 font-semibold text-slate-900">Départs réellement observés</p>
                    <p class="mt-1 text-xs text-slate-500">Champ source : <code>actual_start_at</code></p>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <p class="font-medium text-slate-500">Dates manquantes</p>
                    <p class="mt-1 font-semibold text-slate-900">Remplies à zéro</p>
                    <p class="mt-1 text-xs text-slate-500">Grille locale continue</p>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <p class="font-medium text-slate-500">Historique minimal</p>
                    <p class="mt-1 font-semibold text-slate-900">{{ $contract['minimum_history_days'] }} jours</p>
                    <p class="mt-1 text-xs text-slate-500">Lags jusqu’à J-28</p>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <p class="font-medium text-slate-500">Unité de distance SaaS</p>
                    <p class="mt-1 text-xl font-semibold text-slate-900">{{ $contract['distance_unit'] }}</p>
                    <p class="mt-1 text-xs text-slate-500">Les miles sont refusés ; ce modèle n’utilise pas de variable de distance</p>
                </div>
            </div>
        </x-section-card>

        @if (auth()->user()->hasPermission('prediction.export'))
            <x-filter-panel title="Créer un snapshot d’historique">
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
                    <p class="mt-3 text-sm text-amber-800">La clé de pseudonymisation Intelligence doit être configurée.</p>
                @endunless
            </x-filter-panel>
        @endif

        <x-section-card title="Snapshots disponibles" description="Le CSV et son manifeste servent d’entrée vérifiable au notebook ou au script d’inférence.">
            <x-responsive-table label="Snapshots de demande" class="shadow-none">
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
                                    <p class="mt-1 font-mono text-xs text-slate-500">{{ $run->run_id }}</p>
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
                                            <x-icon-button icon="file" :label="'Consulter le manifeste de '.$run->agency->name" :href="route('intelligence.demand-history.manifest', $run)" data-no-global-loading="true" />
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
                                            <summary class="cursor-pointer text-xs font-medium text-slate-600">Import JSON manuel de secours</summary>
                                            <form method="POST" action="{{ route('intelligence.demand-forecasts.store', $run) }}" enctype="multipart/form-data" class="mt-2 space-y-3" data-loading-form>
                                                @csrf
                                                <x-file-input :id="'forecast-batch-'.$run->id" name="forecast_batch" label="Résultat JSON du modèle" accept="application/json,.json" formats="JSON" required :errors="$errors->get('forecast_batch')" />
                                                <x-submit-button label="Importer en shadow" loading-label="Import en cours…" />
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

        <x-section-card title="Résultats consultatifs" description="P50 représente le scénario central ; P90 un scénario prudent de capacité. Les facteurs sont des sensibilités locales non causales.">
            <div class="space-y-6">
                @forelse ($forecastRuns as $run)
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        @if ($run->executionRun)
                            <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-950">
                                <strong>Inférence HGB réellement exécutée depuis le SaaS</strong>
                                · exécution <span class="font-mono text-xs">{{ $run->executionRun->run_id }}</span>
                                · bundle J5 authentique vérifié avant chargement.
                            </div>
                        @endif
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">Shadow consultatif · {{ $run->agency->name }}</p>
                                <h2 class="mt-1 text-lg font-semibold text-slate-950">Prévision arrêtée au {{ $run->as_of_date->format('d/m/Y') }}</h2>
                                <p class="mt-1 font-mono text-xs text-slate-500">{{ $run->model_name }} {{ $run->model_version }} · {{ $run->run_id }}</p>
                            </div>
                            <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-900">Validation locale en attente</span>
                        </div>

                        <div class="mt-4 rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm leading-6 text-blue-950">
                            Le complément du WAPE public est <span class="font-semibold">{{ $run->publicWapeComplement() }} %</span>, mais il ne constitue pas une accuracy locale. Intervalle et facteurs doivent être interprétés par un responsable humain ; effet technique : <code>{{ $run->operational_effect }}</code>.
                        </div>

                        <div class="mt-4 overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                                        <th class="px-3 py-2">Horizon</th>
                                        <th class="px-3 py-2">Date</th>
                                        <th class="px-3 py-2">Moyenne</th>
                                        <th class="px-3 py-2">Central P50</th>
                                        <th class="px-3 py-2">Prudent P90</th>
                                        <th class="px-3 py-2">Intervalle P05–P95</th>
                                        <th class="px-3 py-2">Facteurs principaux</th>
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
                        title="Aucune prévision locale importée"
                        description="Créez un snapshot, exécutez le modèle en environnement contrôlé, puis importez le JSON muni de son empreinte canonique."
                    />
                @endforelse
            </div>
            <div class="mt-5">{{ $forecastRuns->links() }}</div>
        </x-section-card>
    </div>
</x-app-layout>
