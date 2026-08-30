<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header
            title="Propositions de réallocation OR-Tools"
            eyebrow="Intelligence consultative"
            description="Lancez réellement le solveur OR-Tools sur un scénario synthétique qualifié, puis vérifiez et révisez son résultat sans déplacer aucun véhicule."
        >
            <x-slot:actions>
                @can('viewAny', App\Models\AgencyDistance::class)
                    <a href="{{ route('agency-distances.index') }}" class="rf-button-secondary">Distances inter-agences</a>
                @endcan
                <a href="{{ route('intelligence.fleet-reallocation.index') }}" class="rf-button-secondary">Actualiser</a>
                <a href="{{ route('intelligence.index') }}" class="rf-button-secondary">Retour à Intelligence</a>
            </x-slot:actions>
        </x-page-header>

        <x-form-errors />

        <x-section-card title="Frontière de sécurité" description="La validation humaine reste obligatoire et non opérationnelle.">
            <ul class="list-disc space-y-2 pl-5 text-sm leading-6 text-slate-700">
                <li>Données et nœuds explicitement synthétiques ; aucune identité, coordonnée ou agence réelle dans le payload.</li>
                <li>Distances exclusivement en kilomètres et coûts recalculés côté serveur à 5,00 MAD par véhicule-km.</li>
                <li>CatBoost s’abstient : probabilité de présence 1,000000 et aucune réduction de demande.</li>
                <li>Le statut local reste <code>NOT_VALIDATED_NO_REAL_HISTORY</code>.</li>
                <li>Toute décision conserve l’effet <code>NO_OPERATIONAL_ACTION</code>.</li>
            </ul>
        </x-section-card>

        @if ($canExecute)
            <x-section-card
                title="Lancer un nouveau calcul OR-Tools"
                description="Le bouton crée une exécution fraîche, traitée par la queue PostgreSQL et le script Python qualifié. La prévision HGB reste ici une entrée synthétique clairement signalée, pas une inférence."
            >
                <form method="POST" action="{{ route('intelligence.fleet-reallocation.runs.store') }}" class="grid gap-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                    @csrf
                    <div>
                        <x-input-label for="fleet-reallocation-horizon" value="Horizon de démonstration" required />
                        <select id="fleet-reallocation-horizon" name="forecast_horizon" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" required>
                            @foreach (range(1, 7) as $horizon)
                                <option value="{{ $horizon }}" @selected((int) old('forecast_horizon', 1) === $horizon)>D+{{ $horizon }}</option>
                            @endforeach
                        </select>
                        <x-field-error :messages="$errors->get('forecast_horizon')" class="mt-2" />
                    </div>
                    <x-primary-button>Générer une proposition</x-primary-button>
                </form>
                <p class="mt-3 text-xs leading-5 text-slate-500">
                    En local, le worker <code>php artisan queue:work --queue=intelligence</code> doit être actif. Chaque lancement produit un identifiant et un JSON privés distincts.
                </p>
            </x-section-card>
        @endif

        <x-section-card title="Dernières exécutions" description="Ce registre permet de distinguer une proposition réellement calculée d’un fichier simplement importé.">
            @if ($runtimeRuns->isEmpty())
                <x-empty-state title="Aucune exécution lancée" description="Utilisez « Générer une proposition » pour créer le premier calcul OR-Tools depuis le SaaS." />
            @else
                <x-responsive-table label="Dernières exécutions OR-Tools" class="shadow-none">
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
            <x-section-card title="Importer une proposition privée" description="Le tenant provient uniquement de la session ; aucun identifiant de périmètre n’est accepté dans le formulaire.">
                <form method="POST" action="{{ route('intelligence.fleet-reallocation.store') }}" enctype="multipart/form-data" class="grid gap-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                    @csrf
                    <div>
                        <x-input-label for="reallocation-proposal" value="Fichier JSON contractuel" required />
                        <input id="reallocation-proposal" name="proposal" type="file" accept="application/json,.json" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white p-2 text-sm" required>
                        <x-field-error :messages="$errors->get('proposal')" class="mt-2" />
                    </div>
                    <x-primary-button>Vérifier et importer</x-primary-button>
                </form>
            </x-section-card>
        @endif

        <x-result-count :paginator="$proposals" />

        @forelse ($proposals as $proposal)
            <x-section-card
                id="proposal-{{ $proposal->proposal_id }}"
                title="Plan D+{{ $proposal->forecast_horizon }} · {{ $proposal->target_date->format('d/m/Y') }}"
                description="{{ $proposal->solver_name }} {{ $proposal->solver_version }} · {{ $proposal->solver_status }}"
            >
                <div class="mb-4 rounded-xl border border-slate-200 bg-white p-3 text-sm text-slate-700">
                    @if ($proposal->runtimeRun)
                        <strong>Calcul réellement exécuté depuis le SaaS</strong> · exécution {{ $proposal->runtimeRun->run_id }}
                    @else
                        <strong>Résultat importé</strong> · aucune exécution Python associée dans le SaaS
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
                        <p class="mt-1 font-semibold">{{ $proposal->relocated_vehicle_count }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <p class="text-slate-500">Temps solveur</p>
                        <p class="mt-1 font-semibold">{{ $proposal->solver_runtime_ms }} ms</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <p class="text-slate-500">Revue humaine</p>
                        <p class="mt-1 font-semibold">{{ $proposal->decision?->decision->label() ?? 'En attente' }}</p>
                    </div>
                </div>

                <div class="mt-5">
                    <x-responsive-table label="Déplacements synthétiques proposés" class="shadow-none">
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
                                        <td>{{ $move->distance_km }} km</td>
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
                        <p>HGB consultatif · CatBoost refusé · validation locale absente</p>
                    </div>
                    <div class="flex flex-wrap items-end gap-3">
                        <a href="{{ route('intelligence.fleet-reallocation.download', $proposal) }}" class="rf-button-secondary">Télécharger la preuve</a>
                        @can('review', $proposal)
                            @if ($proposal->decision === null)
                                <form method="POST" action="{{ route('intelligence.fleet-reallocation.decisions.store', $proposal) }}" class="flex flex-wrap items-end gap-2">
                                    @csrf
                                    <input type="hidden" name="decision" value="accepted_for_demo_review">
                                    <div>
                                        <x-input-label for="accept-reallocation-reason-{{ $proposal->id }}" value="Motif d’acceptation" />
                                        <select id="accept-reallocation-reason-{{ $proposal->id }}" name="reason_code" class="mt-1 rounded-lg border-slate-300 text-sm">
                                            @foreach ($acceptReasonCodes as $reasonCode)<option value="{{ $reasonCode }}">{{ $reasonCode }}</option>@endforeach
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
                                            @foreach ($rejectReasonCodes as $reasonCode)<option value="{{ $reasonCode }}">{{ $reasonCode }}</option>@endforeach
                                        </select>
                                    </div>
                                    <x-secondary-button type="submit">Rejeter</x-secondary-button>
                                </form>
                            @else
                                <p class="text-sm font-medium text-slate-700">Décision append-only déjà enregistrée.</p>
                            @endif
                        @endcan
                    </div>
                </div>
            </x-section-card>
        @empty
            <x-empty-state title="Aucune proposition importée" description="Le registre reste vide tant qu’aucun résultat OR-Tools synthétique conforme n’est fourni." />
        @endforelse

        {{ $proposals->links() }}
    </div>
</x-app-layout>
