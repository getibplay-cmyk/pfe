<x-app-layout>
    <div class="rf-page">
        <x-page-header
            title="Usage atypique à vérifier"
            eyebrow="Aide à la décision"
            description="Une file consultative pour vérifier certains retours de location, sans modifier les contrats ni la facturation."
        >
            <x-slot:actions>
                <a href="{{ route('intelligence.index') }}" class="rf-button-secondary">Retour aux analyses</a>
            </x-slot:actions>
        </x-page-header>

        <x-form-errors />

        <div class="rounded-2xl border border-blue-200 bg-blue-50 p-5 text-sm leading-6 text-blue-950">
            Cette indication statistique sert uniquement à orienter une vérification humaine. Elle ne déclenche aucun changement automatique sur la location, le véhicule ou les éléments financiers.
        </div>

        <x-filter-panel title="Filtrer la file">
            <form method="GET" action="{{ route('intelligence.rental-usage-anomalies.index') }}" class="grid gap-4 md:grid-cols-5 md:items-end">
                <div>
                    <x-input-label for="anomaly-agency" value="Agence" />
                    <select id="anomaly-agency" name="agency" class="mt-1 block w-full rounded-lg border-slate-300">
                        @if (auth()->user()->agency_id === null)
                            <option value="">Toutes les agences autorisées</option>
                        @endif
                        @foreach ($agencies as $agency)
                            <option value="{{ $agency->id }}" @selected((string) $filters['agency'] === (string) $agency->id)>{{ $agency->name }}</option>
                        @endforeach
                    </select>
                    <x-field-error :messages="$errors->get('agency')" />
                </div>
                <div>
                    <x-input-label for="anomaly-date-from" value="Retour à partir du" />
                    <input id="anomaly-date-from" type="date" name="date_from" value="{{ $filters['date_from'] }}" class="mt-1 block w-full rounded-lg border-slate-300">
                    <x-field-error :messages="$errors->get('date_from')" />
                </div>
                <div>
                    <x-input-label for="anomaly-date-to" value="Retour jusqu’au" />
                    <input id="anomaly-date-to" type="date" name="date_to" value="{{ $filters['date_to'] }}" class="mt-1 block w-full rounded-lg border-slate-300">
                    <x-field-error :messages="$errors->get('date_to')" />
                </div>
                <div>
                    <x-input-label for="anomaly-review-state" value="État de la revue" />
                    <select id="anomaly-review-state" name="review_state" class="mt-1 block w-full rounded-lg border-slate-300">
                        <option value="">Tous les états</option>
                        <option value="pending" @selected($filters['review_state'] === 'pending')>Vérification humaine nécessaire</option>
                        @foreach (App\Enums\RentalUsageAnomalyReviewDecision::cases() as $decision)
                            <option value="{{ $decision->value }}" @selected($filters['review_state'] === $decision->value)>{{ $decision->label() }}</option>
                        @endforeach
                    </select>
                    <x-field-error :messages="$errors->get('review_state')" />
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-primary-button>Filtrer</x-primary-button>
                    <a href="{{ route('intelligence.rental-usage-anomalies.index') }}" class="rf-button-secondary">Réinitialiser</a>
                </div>
            </form>
        </x-filter-panel>

        @if ($canRun)
            <x-section-card title="Nouvelle analyse" description="Le lancement utilise la source préparée la plus récente de votre périmètre.">
                @if ($launchSourceAvailable)
                    <form method="POST" action="{{ route('intelligence.rental-usage-anomalies.runs.store') }}">
                        @csrf
                        <x-primary-button>Lancer l’analyse</x-primary-button>
                    </form>
                @else
                    <p class="text-sm text-slate-600">Aucune source préparée n’est actuellement disponible.</p>
                @endif
                @if ($canExport)
                    <a href="{{ route('intelligence.index') }}" class="rf-button-link mt-3">Gérer les données préparées</a>
                @endif
            </x-section-card>
        @endif

        <x-section-card
            title="Cas à vérifier"
            description="{{ number_format($results->total(), 0, ',', ' ') }} cas dans la sélection actuelle."
        >
            @if ($selectedRun)
                <p class="mb-5 text-sm text-slate-600">
                    Analyse du {{ App\Support\Ui\UiLabel::dateTime($selectedRun->requested_at) }}.
                </p>
            @endif

            <div class="space-y-4">
                @forelse ($results as $result)
                    <article data-anomaly-case class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $result->agency->name }}</p>
                                <h2 class="mt-1 text-lg font-semibold text-slate-950">Contrat {{ $result->rentalContract->contract_number }}</h2>
                                <p class="mt-1 text-sm text-slate-600">Retour le {{ App\Support\Ui\UiLabel::dateTime($result->event_at) }}</p>
                            </div>
                            <div class="rounded-xl bg-slate-50 px-4 py-3 text-right">
                                <p class="text-xs text-slate-500">Indicateur statistique</p>
                                <p class="mt-1 font-semibold">Rang {{ number_format($result->primary_rank, 0, ',', ' ') }} sur {{ number_format($selectedRun->source_row_count, 0, ',', ' ') }}</p>
                            </div>
                        </div>

                        <div class="mt-4">
                            <p class="text-sm font-semibold text-slate-900">Facteurs observés</p>
                            <div class="mt-2 grid gap-3 sm:grid-cols-2">
                                @foreach (array_slice($result->primary_factors, 0, 2) as $factor)
                                    <div data-anomaly-factor class="rounded-xl border border-slate-200 p-3 text-sm">
                                        <p class="text-slate-500">{{ App\Support\Intelligence\RentalUsageAnomaly\RentalUsageAnomalyContract::featureLabel($factor['feature']) }}</p>
                                        <p class="mt-1 font-semibold">
                                            {{ number_format((float) $factor['value'], 2, ',', ' ') }}
                                            {{ App\Support\Intelligence\RentalUsageAnomaly\RentalUsageAnomalyContract::featureUnit($factor['feature']) }}
                                        </p>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="mt-4 rounded-xl border border-slate-200 p-4 text-sm">
                            <p class="font-semibold">{{ $result->reviewStatusLabel() }}</p>
                            @if ($result->latestReview?->note)
                                <p class="mt-1 whitespace-pre-line text-slate-600">{{ $result->latestReview->note }}</p>
                            @endif
                        </div>

                        @if ($canReview)
                            <form method="POST" action="{{ route('intelligence.rental-usage-anomalies.contract-reviews.store', $result->rentalContract) }}" class="mt-4 grid gap-3 md:grid-cols-[minmax(0,15rem)_minmax(0,1fr)_auto] md:items-end">
                                @csrf
                                <div>
                                    <x-input-label :for="'anomaly-decision-'.$loop->index" value="Décision de vérification" required />
                                    <select id="anomaly-decision-{{ $loop->index }}" name="decision" class="mt-1 block w-full rounded-lg border-slate-300" required>
                                        @foreach (App\Enums\RentalUsageAnomalyReviewDecision::cases() as $decision)
                                            <option value="{{ $decision->value }}">{{ $decision->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <x-input-label :for="'anomaly-note-'.$loop->index" value="Note factuelle facultative" />
                                    <x-text-input :id="'anomaly-note-'.$loop->index" name="note" maxlength="500" class="mt-1 block w-full" />
                                </div>
                                <x-primary-button>Marquer comme vérifié</x-primary-button>
                            </form>
                        @endif

                        @can('view', $result->rentalContract)
                            <p class="mt-4"><a class="rf-button-link" href="{{ route('contracts.show', $result->rentalContract) }}">Ouvrir le contrat</a></p>
                        @endcan
                    </article>
                @empty
                    <x-empty-state title="Aucun cas à vérifier" description="Aucun retour ne correspond aux filtres dans votre périmètre autorisé." />
                @endforelse
            </div>

            @if ($results->hasPages())
                <div class="mt-5">{{ $results->links() }}</div>
            @endif
        </x-section-card>
    </div>
</x-app-layout>
