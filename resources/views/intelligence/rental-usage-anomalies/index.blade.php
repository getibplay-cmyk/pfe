<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header
            title="Usages de location atypiques"
            eyebrow="Intelligence consultative"
            description="Classement CPU explicable des retours RentFleet v1.1, avec challenger indépendant et revue humaine append-only."
        />

        <div class="rounded-2xl border border-amber-300 bg-amber-50 p-5 text-sm leading-6 text-amber-950">
            <p class="font-semibold">Un score élevé n’est ni une preuve de fraude, ni une faute, ni une probabilité.</p>
            <p class="mt-1">Ce module ne peut créer aucune sanction, aucun frais, aucune accusation, aucune modification de contrat. Il prépare uniquement un petit échantillon pour vérification humaine.</p>
        </div>

        <x-section-card title="Contrat du classement" description="Le modèle principal décide du budget de revue ; le challenger mesure seulement le désaccord.">
            <dl class="grid gap-3 text-sm md:grid-cols-4">
                <div class="rounded-xl bg-slate-50 p-4"><dt class="text-slate-500">Principal</dt><dd class="mt-1 font-semibold"><code>robust_mad_top2</code></dd></div>
                <div class="rounded-xl bg-slate-50 p-4"><dt class="text-slate-500">Challenger</dt><dd class="mt-1 font-semibold">Isolation Forest</dd></div>
                <div class="rounded-xl bg-slate-50 p-4"><dt class="text-slate-500">Budget par défaut</dt><dd class="mt-1 font-semibold">1 %</dd></div>
                <div class="rounded-xl bg-slate-50 p-4"><dt class="text-slate-500">Calcul</dt><dd class="mt-1 font-semibold">CPU · historique minimal {{ $runtime['minimum_rows'] }}</dd></div>
            </dl>
            <p class="mt-4 text-sm leading-6 text-slate-600">Le score principal est la moyenne des deux plus grands écarts robustes positifs à la médiane parmi le retard, les kilomètres/jour et la baisse de carburant. L’Isolation Forest ne remplace jamais ce classement.</p>
        </x-section-card>

        <x-section-card title="Analyser un snapshot RentFleet v1.1" description="Le CSV privé existant est lu directement ; il n’est ni téléversé vers Colab, ni copié dans GitHub.">
            @if ($canRun)
                <x-responsive-table label="Snapshots disponibles" class="shadow-none">
                    <table>
                        <thead><tr><th>Snapshot</th><th>Période</th><th>Lignes</th><th>Admissibilité</th><th><span class="sr-only">Action</span></th></tr></thead>
                        <tbody>
                            @forelse ($exports as $export)
                                <tr>
                                    <td class="font-mono text-xs">{{ $export->run_id }}</td>
                                    <td>{{ $export->date_from->format('d/m/Y') }} → {{ $export->date_to->format('d/m/Y') }}</td>
                                    <td>{{ number_format($export->row_count, 0, ',', ' ') }}</td>
                                    <td>{{ $export->row_count >= $runtime['minimum_rows'] ? 'Classement possible' : 'Abstention attendue : historique insuffisant' }}</td>
                                    <td class="text-right">
                                        <form method="POST" action="{{ route('intelligence.rental-usage-anomalies.store', $export) }}">
                                            @csrf
                                            <x-primary-button>Analyser sur CPU</x-primary-button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="p-8 text-center text-slate-500">Créez d’abord un export réel v1.1 depuis l’écran Intelligence.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-responsive-table>
            @else
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
                    Le lancement est fermé. Installez l’environnement Python figé puis activez <code>RENTFLEET_ANOMALY_V1_ENABLED</code>. La consultation du registre reste disponible.
                </div>
            @endif
        </x-section-card>

        <x-section-card title="Registre des exécutions" description="Chaque état terminal est immuable ; les résultats et revues sont append-only dans PostgreSQL.">
            <x-responsive-table label="Exécutions des usages atypiques" class="shadow-none">
                <table>
                    <thead><tr><th>Exécution</th><th>Snapshot</th><th>État</th><th>Lignes / candidats</th><th>Demandée le</th><th><span class="sr-only">Consulter</span></th></tr></thead>
                    <tbody>
                        @forelse ($runs as $run)
                            <tr>
                                <td class="font-mono text-xs">{{ $run->run_id }}</td>
                                <td class="font-mono text-xs">{{ $run->exportRun->run_id }}</td>
                                <td><x-status-badge :value="$run->status" /></td>
                                <td>{{ number_format($run->source_row_count, 0, ',', ' ') }} / {{ $run->candidate_count === null ? '—' : number_format($run->candidate_count, 0, ',', ' ') }}</td>
                                <td>{{ App\Support\Ui\UiLabel::dateTime($run->requested_at) }}</td>
                                <td class="text-right"><a class="font-medium text-indigo-700" href="{{ route('intelligence.rental-usage-anomalies.index', ['run' => $run->run_id, 'budget' => $budget]) }}">Consulter</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-8 text-center text-slate-500">Aucune exécution dans votre périmètre.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-responsive-table>
            <div class="mt-5">{{ $runs->links() }}</div>
        </x-section-card>

        @if ($selectedRun)
            <x-section-card title="Résultat {{ $selectedRun->run_id }}" description="Le budget change seulement le nombre de lignes affichées ; il ne recalcule ni ne réécrit l’exécution.">
                @if ($selectedRun->status->value === 'failed')
                    <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900">{{ $selectedRun->failureLabel() }}</div>
                @elseif (in_array($selectedRun->status->value, ['queued', 'running'], true))
                    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950">Traitement en attente sur la queue <code>intelligence</code>. Aucun effet métier n’est en attente.</div>
                @elseif ($selectedRun->data_status === 'insufficient_data')
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
                        Abstention : {{ $selectedRun->source_row_count }} retours admissibles, minimum {{ $selectedRun->minimum_rows }}. Aucun classement ni candidat n’a été produit.
                    </div>
                @else
                    @php($budgetSummary = collect($selectedRun->budget_results)->firstWhere('basis_points', $budget))
                    <form method="GET" action="{{ route('intelligence.rental-usage-anomalies.index') }}" class="flex flex-wrap items-end gap-3">
                        <input type="hidden" name="run" value="{{ $selectedRun->run_id }}">
                        <div>
                            <x-input-label for="anomaly-budget" value="Budget de revue" />
                            <select id="anomaly-budget" name="budget" class="mt-1 rounded-lg border-slate-300">
                                <option value="50" @selected($budget === 50)>0,5 %</option>
                                <option value="100" @selected($budget === 100)>1 % · défaut</option>
                                <option value="200" @selected($budget === 200)>2 %</option>
                            </select>
                        </div>
                        <x-primary-button>Afficher</x-primary-button>
                    </form>

                    <dl class="mt-5 grid gap-3 text-sm md:grid-cols-4">
                        <div class="rounded-xl bg-slate-50 p-4"><dt class="text-slate-500">À revoir</dt><dd class="mt-1 text-xl font-semibold">{{ $budgetSummary['selected_count'] }}</dd></div>
                        <div class="rounded-xl bg-slate-50 p-4"><dt class="text-slate-500">Taux réalisé</dt><dd class="mt-1 text-xl font-semibold">{{ number_format($budgetSummary['realized_rate'] * 100, 2, ',', ' ') }} %</dd></div>
                        <div class="rounded-xl bg-slate-50 p-4"><dt class="text-slate-500">Accords challenger</dt><dd class="mt-1 text-xl font-semibold">{{ $budgetSummary['agreement_count'] }} / {{ $budgetSummary['selected_count'] }}</dd></div>
                        <div class="rounded-xl bg-slate-50 p-4"><dt class="text-slate-500">Jaccard</dt><dd class="mt-1 text-xl font-semibold">{{ number_format($budgetSummary['jaccard'] * 100, 1, ',', ' ') }} %</dd></div>
                    </dl>
                    <p class="mt-3 text-xs leading-5 text-slate-500">Le Jaccard mesure l’accord entre deux classements non supervisés ; il ne mesure pas leur exactitude.</p>

                    <div class="mt-6 space-y-4">
                        @forelse ($results as $result)
                            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Rang principal #{{ $result->primary_rank }} · {{ $result->agency->name }}</p>
                                        <h3 class="mt-1 text-lg font-semibold text-slate-950">Contrat {{ $result->rentalContract->contract_number }}</h3>
                                        <p class="text-sm text-slate-600">{{ $result->rentalContract->vehicle->registration_number }} · retour {{ App\Support\Ui\UiLabel::dateTime($result->event_at) }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-mono text-sm font-semibold">Indice MAD {{ number_format((float) $result->primary_score, 3, ',', ' ') }}</p>
                                        <p class="mt-1 text-xs {{ $result->challengerSelectedForBudget($budget) ? 'text-emerald-700' : 'text-slate-500' }}">
                                            {{ $result->challengerSelectedForBudget($budget) ? 'Challenger d’accord' : 'Challenger en désaccord' }} · rang #{{ $result->challenger_rank }}
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                                    <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Retard</p><p class="font-semibold">{{ number_format((float) $result->late_hours, 2, ',', ' ') }} h</p></div>
                                    <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Kilomètres / jour</p><p class="font-semibold">{{ number_format((float) $result->km_per_day, 2, ',', ' ') }}</p></div>
                                    <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Baisse carburant</p><p class="font-semibold">{{ number_format((float) $result->fuel_drop_pct, 2, ',', ' ') }} points</p></div>
                                </div>
                                <div class="mt-4 rounded-xl border border-slate-200 p-4 text-sm">
                                    <p class="font-semibold">Deux facteurs qui expliquent le score</p>
                                    <ul class="mt-2 space-y-1 text-slate-600">
                                        @foreach ($result->primary_factors as $factor)
                                            <li>{{ App\Support\Intelligence\RentalUsageAnomaly\RentalUsageAnomalyContract::featureLabel($factor['feature']) }} : écart robuste positif {{ number_format($factor['positive_robust_deviation'], 2, ',', ' ') }}</li>
                                        @endforeach
                                    </ul>
                                </div>

                                @if ($result->latestReview)
                                    <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950">
                                        <p class="font-semibold">Dernière revue : {{ $result->latestReview->decision->label() }}</p>
                                        @if ($result->latestReview->note)<p class="mt-1 whitespace-pre-line">{{ $result->latestReview->note }}</p>@endif
                                        <p class="mt-2 text-xs">{{ $result->reviews_count }} événement(s) conservé(s), aucun remplacé.</p>
                                    </div>
                                @endif

                                @if ($canReview)
                                    <form method="POST" action="{{ route('intelligence.rental-usage-anomalies.reviews.store', $result) }}" class="mt-4 grid gap-3 md:grid-cols-[minmax(0,15rem)_minmax(0,1fr)_auto] md:items-end">
                                        @csrf
                                        <input type="hidden" name="budget" value="{{ $budget }}">
                                        <div>
                                            <x-input-label :for="'anomaly-decision-'.$result->id" value="Revue humaine" required />
                                            <select id="anomaly-decision-{{ $result->id }}" name="decision" class="mt-1 block w-full rounded-lg border-slate-300" required>
                                                <option value="follow_up">Conserver pour vérification</option>
                                                <option value="dismissed">Écarter après vérification</option>
                                                <option value="needs_information">Informations requises</option>
                                            </select>
                                        </div>
                                        <div>
                                            <x-input-label :for="'anomaly-note-'.$result->id" value="Note factuelle facultative" />
                                            <x-text-input :id="'anomaly-note-'.$result->id" name="note" maxlength="500" class="mt-1 block w-full" />
                                        </div>
                                        <x-primary-button>Ajouter au registre</x-primary-button>
                                    </form>
                                @endif

                                @if (auth()->user()->hasPermission('contract.view'))
                                    <p class="mt-4"><a class="font-medium text-indigo-700" href="{{ route('contracts.show', $result->rentalContract) }}">Ouvrir le contrat en lecture autorisée</a></p>
                                @endif
                            </article>
                        @empty
                            <x-empty-state title="Aucun résultat à ce budget" description="Le classement terminé ne contient aucune ligne dans votre périmètre autorisé." />
                        @endforelse
                    </div>
                @endif
            </x-section-card>
        @endif
    </div>
</x-app-layout>
