<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header
            title="Suggestions de réallocation"
            eyebrow="Aide à la décision"
            description="Générez une suggestion pour équilibrer la disponibilité entre agences, puis vérifiez-la avant toute décision. Aucun véhicule n’est déplacé automatiquement."
        >
            <x-slot:actions>
                @can('viewAny', App\Models\AgencyDistance::class)
                    <a href="{{ route('agency-distances.index') }}" class="rf-button-secondary">Distances inter-agences</a>
                @endcan
                <a href="{{ route('intelligence.fleet-reallocation.index') }}" class="rf-button-secondary"><x-icon name="refresh" size="xs" />Actualiser</a>
                <a href="{{ route('intelligence.index') }}" class="rf-button-secondary"><x-icon name="previous" size="xs" />Retour aux analyses</a>
            </x-slot:actions>
        </x-page-header>

        <x-form-errors />

        <x-section-card title="Règles de décision" description="Chaque suggestion doit être vérifiée par un responsable avant toute organisation de la flotte.">
            <ul class="list-disc space-y-2 pl-5 text-sm leading-6 text-slate-700">
                <li>Les distances entre agences et les coûts estimés sont contrôlés par {{ config('brand.name') }}.</li>
                <li>La suggestion sert uniquement à préparer le planning.</li>
                <li>Aucun véhicule, réservation ou contrat n’est modifié automatiquement.</li>
                <li>Le responsable garde la décision finale et peut accepter ou rejeter la suggestion.</li>
            </ul>
        </x-section-card>

        @if ($canExecute)
            <x-section-card
                title="Générer une suggestion"
                description="Choisissez le jour à analyser. Le calcul utilise les prévisions et les distances disponibles, sans déplacer automatiquement de véhicule."
            >
                <form method="POST" action="{{ route('intelligence.fleet-reallocation.runs.store') }}" class="grid gap-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                    @csrf
                    <div>
                        <x-input-label for="fleet-reallocation-horizon" value="Jour à analyser" required />
                        <select id="fleet-reallocation-horizon" name="forecast_horizon" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" required>
                            @foreach (range(1, 7) as $horizon)
                                <option value="{{ $horizon }}" @selected((int) old('forecast_horizon', 1) === $horizon)>D+{{ $horizon }}</option>
                            @endforeach
                        </select>
                        <x-field-error :messages="$errors->get('forecast_horizon')" class="mt-2" />
                    </div>
                    <x-primary-button>Générer une proposition</x-primary-button>
                </form>
                <p class="mt-3 text-xs leading-5 text-slate-500">Le calcul peut prendre quelques instants. Actualisez la page pour suivre son avancement.</p>
            </x-section-card>
        @endif

        <x-section-card title="Demandes récentes" description="Suivez l’état des calculs et ouvrez les suggestions disponibles.">
            @if ($runtimeRuns->isEmpty())
                <x-empty-state title="Aucune suggestion demandée" description="Utilisez « Générer une proposition » pour préparer la première suggestion." />
            @else
                <x-responsive-table label="Demandes récentes de réallocation" class="shadow-none">
                    <table>
                        <thead>
                            <tr>
                                <th>Demandée</th>
                                <th>Horizon</th>
                                <th>État</th>
                                <th>Résultat</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($runtimeRuns as $run)
                                <tr>
                                    <td>{{ App\Support\Ui\UiLabel::dateTime($run->requested_at) }}</td>
                                    <td>D+{{ $run->forecast_horizon }}</td>
                                    <td>
                                        <span class="font-medium">{{ $run->status->label() }}</span>
                                        @if ($run->failure_code)
                                            <p class="mt-1 text-xs text-red-700">Le calcul n’a pas pu aboutir. Vérifiez la disponibilité de l’assistance avant de réessayer.</p>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($run->proposal)
                                            <a href="#proposal-{{ $run->proposal->proposal_id }}" class="font-medium text-fleet-700 underline">Voir la proposition</a>
                                        @else
                                            <span class="text-slate-500">Pas encore disponible</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-responsive-table>
            @endif
        </x-section-card>

        @if ($canImport)
            <x-section-card title="Importer une suggestion" description="Le fichier reste privé et son périmètre est déterminé automatiquement à partir de votre session.">
                <form method="POST" action="{{ route('intelligence.fleet-reallocation.store') }}" enctype="multipart/form-data" class="grid gap-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-end" data-loading-form>
                    @csrf
                    <x-file-input id="reallocation-proposal" name="proposal" label="Fichier de suggestion" accept="application/json,.json" formats="JSON" required :errors="$errors->get('proposal')" />
                    <x-submit-button label="Vérifier et importer" loading-label="Vérification…" />
                </form>
            </x-section-card>
        @endif

        <x-result-count :paginator="$proposals" />

        @forelse ($proposals as $proposal)
            <x-section-card
                id="proposal-{{ $proposal->proposal_id }}"
                title="Plan D+{{ $proposal->forecast_horizon }} · {{ $proposal->target_date->format('d/m/Y') }}"
                description="Suggestion à vérifier avant toute décision"
            >
                <div class="mb-4 rounded-xl border border-slate-200 bg-white p-3 text-sm text-slate-700">
                    @if ($proposal->runtimeRun)
                        <strong>Suggestion calculée depuis {{ config('brand.name') }}.</strong>
                    @else
                        <strong>Suggestion importée.</strong> Vérifiez les informations avant de prendre une décision.
                    @endif
                </div>
                <div class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-5">
                    <div class="rounded-xl bg-slate-50 p-3">
                        <p class="text-slate-500">Service</p>
                        <p class="mt-1 font-semibold">{{ $proposal->served_demand }} / {{ $proposal->total_demand }}</p>
                        <p class="text-xs text-slate-500">ratio {{ $proposal->service_rate }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <p class="text-slate-500">Non servie</p>
                        <p class="mt-1 font-semibold">{{ $proposal->unserved_demand }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <p class="text-slate-500">Véhicules proposés</p>
                        <p class="mt-1 font-semibold">{{ App\Support\Ui\BusinessNumber::integer($proposal->relocated_vehicle_count) }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <p class="text-slate-500">Revue humaine</p>
                        <p class="mt-1 font-semibold">{{ $proposal->decision?->decision->label() ?? 'En attente' }}</p>
                    </div>
                </div>

                <div class="mt-5">
                    <x-responsive-table label="Déplacements proposés" class="shadow-none">
                        <table>
                            <thead>
                                <tr>
                                    <th>Origine</th>
                                    <th>Destination</th>
                                    <th>Véhicules</th>
                                    <th>Distance</th>
                                    <th>Effet</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($proposal->moves as $move)
                                    <tr>
                                        <td class="font-mono text-xs">{{ $move->from_node_ref }}</td>
                                        <td class="font-mono text-xs">{{ $move->to_node_ref }}</td>
                                        <td>{{ $move->vehicles }}</td>
                                        <td>{{ App\Support\Ui\BusinessNumber::distance($move->distance_km) }}</td>
                                        <td>Aucune action automatique</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-responsive-table>
                </div>

                <div class="mt-5 flex flex-wrap items-end justify-between gap-4 border-t border-slate-200 pt-4">
                    <div class="text-xs leading-5 text-slate-500">
                        <p>Importé le {{ App\Support\Ui\UiLabel::dateTime($proposal->imported_at) }}</p>
                        <p>Suggestion consultative · validation humaine obligatoire</p>
                    </div>
                    <div class="flex flex-wrap items-end gap-3">
                        <a href="{{ route('intelligence.fleet-reallocation.download', $proposal) }}" class="rf-button-secondary" data-no-global-loading="true"><x-icon name="download" size="xs" />Télécharger la preuve</a>
                        @can('review', $proposal)
                            @if ($proposal->decision === null)
                                <form method="POST" action="{{ route('intelligence.fleet-reallocation.decisions.store', $proposal) }}" class="flex flex-wrap items-end gap-2">
                                    @csrf
                                    <input type="hidden" name="decision" value="accepted_for_demo_review">
                                    <div>
                                        <x-input-label for="accept-reallocation-reason-{{ $proposal->id }}" value="Motif d’acceptation" />
                                        <select id="accept-reallocation-reason-{{ $proposal->id }}" name="reason_code" class="mt-1 rounded-lg border-slate-300 text-sm">
                                            @foreach ($acceptReasonCodes as $reasonCode)<option value="{{ $reasonCode }}">{{ App\Support\Ui\UiLabel::get($reasonCode) }}</option>@endforeach
                                        </select>
                                    </div>
                                    <x-primary-button>Accepter pour la démo</x-primary-button>
                                </form>
                                <form method="POST" action="{{ route('intelligence.fleet-reallocation.decisions.store', $proposal) }}" class="flex flex-wrap items-end gap-2">
                                    @csrf
                                    <input type="hidden" name="decision" value="rejected">
                                    <div>
                                        <x-input-label for="reject-reallocation-reason-{{ $proposal->id }}" value="Motif de rejet" />
                                        <select id="reject-reallocation-reason-{{ $proposal->id }}" name="reason_code" class="mt-1 rounded-lg border-slate-300 text-sm">
                                            @foreach ($rejectReasonCodes as $reasonCode)<option value="{{ $reasonCode }}">{{ App\Support\Ui\UiLabel::get($reasonCode) }}</option>@endforeach
                                        </select>
                                    </div>
                                    <x-secondary-button type="submit">Rejeter</x-secondary-button>
                                </form>
                            @else
                                <p class="text-sm font-medium text-slate-700">Décision déjà enregistrée et conservée dans l’historique.</p>
                            @endif
                        @endcan
                    </div>
                </div>
            </x-section-card>
        @empty
            <x-empty-state title="Aucune suggestion disponible" description="Générez ou importez une suggestion pour commencer." />
        @endforelse

        {{ $proposals->links() }}
    </div>
</x-app-layout>
