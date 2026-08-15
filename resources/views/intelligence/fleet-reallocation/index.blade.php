<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header
            title="Propositions de réallocation OR-Tools"
            eyebrow="Intelligence consultative"
            description="Consultez et révisez des plans synthétiques qualifiés, sans déplacer aucun véhicule."
        >
            <x-slot:actions>
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
                title="Plan D+{{ $proposal->forecast_horizon }} · {{ $proposal->target_date->format('d/m/Y') }}"
                description="{{ $proposal->solver_name }} {{ $proposal->solver_version }} · {{ $proposal->solver_status }}"
            >
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
