<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header
            title="Distances inter-agences"
            eyebrow="Configuration de la flotte"
            description="Renseignez des distances directionnelles vérifiées pour préparer les futurs conseils de rééquilibrage."
        >
            <x-slot:actions>
                @can('viewAny', App\Models\FleetReallocationProposal::class)
                    <a href="{{ route('intelligence.fleet-reallocation.index') }}" class="rf-button-secondary">Retour aux propositions de démonstration</a>
                @endcan
                <a href="{{ route('vehicles.index') }}" class="rf-button-secondary">Retour à la flotte</a>
            </x-slot:actions>
        </x-page-header>

        <x-flash-message />
        <x-form-errors />

        <x-section-card
            title="État de préparation"
            description="Ce contrôle ne lance aucun calcul et ne déplace aucun véhicule."
        >
            <div class="flex flex-wrap items-center gap-3">
                <x-status-badge :value="$readiness->ready() ? 'ready' : 'incomplete'" />
                <p class="text-sm text-slate-700">
                    {{ $readiness->ready() ? 'Les prérequis sont disponibles.' : 'Des informations restent à compléter.' }}
                </p>
            </div>
            @if (!$readiness->ready())
                <ul class="mt-4 list-disc space-y-2 pl-5 text-sm text-slate-700">
                    @if (in_array('missing_agencies', $readiness->issues, true))
                        <li>Au moins deux agences actives accessibles sont nécessaires.</li>
                    @endif
                    @if (in_array('missing_distances', $readiness->issues, true))
                        <li>Certaines distances inter-agences doivent être renseignées avant de calculer un rééquilibrage réel.</li>
                    @endif
                    @if (in_array('missing_forecasts', $readiness->issues, true))
                        <li>Les prévisions de certaines agences doivent être actualisées.</li>
                    @endif
                    @if (in_array('incompatible_forecasts', $readiness->issues, true))
                        <li>Les prévisions disponibles ne couvrent pas toutes la même période et les sept prochains jours.</li>
                    @endif
                    @if (in_array('runtime_unavailable', $readiness->issues, true))
                        <li>Le service local de calcul doit être vérifié avant utilisation.</li>
                    @endif
                </ul>
            @endif
            <div class="mt-4 rounded-lg bg-slate-50 p-4 text-sm text-slate-700">
                <p><strong>Départs prévus :</strong> moyenne conditionnelle conservée sans modification.</p>
                <p class="mt-1"><strong>Besoin de planification arrondi à l’unité supérieure :</strong> conversion séparée utilisée uniquement lors d’un futur calcul.</p>
            </div>
        </x-section-card>

        <x-section-card
            title="Couverture directionnelle"
            description="Une valeur est requise dans chaque sens ; aucune valeur par défaut n’est ajoutée."
        >
            @if ($readiness->matrix->complete())
                <p class="text-sm font-medium text-emerald-700">Toutes les directions entre agences actives sont renseignées.</p>
            @elseif ($readiness->matrix->missingPairs === [])
                <p class="text-sm font-medium text-rose-700">Les distances configurées doivent être vérifiées.</p>
            @else
                <x-responsive-table label="Directions encore manquantes" class="shadow-none">
                    <table>
                        <thead>
                            <tr><th>Départ</th><th>Arrivée</th><th>État</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($readiness->matrix->missingPairs as $pair)
                                <tr>
                                    <td>{{ $agencyNames[$pair['from_agency_id']] ?? 'Agence indisponible' }}</td>
                                    <td>{{ $agencyNames[$pair['to_agency_id']] ?? 'Agence indisponible' }}</td>
                                    <td><x-status-badge value="incomplete" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-responsive-table>
            @endif
        </x-section-card>

        @if ($canManage)
            <x-section-card
                title="Ajouter une distance vérifiée"
                description="La référence est informative ; RentFleet ne la consulte jamais automatiquement."
            >
                <form method="POST" action="{{ route('agency-distances.store') }}" class="grid gap-4 md:grid-cols-2">
                    @csrf
                    <div>
                        <x-input-label for="from_agency_id" value="Agence de départ" required />
                        <select id="from_agency_id" name="from_agency_id" required class="mt-1 block w-full rounded-lg border-slate-300">
                            <option value="">Sélectionner</option>
                            @foreach ($agencies as $agency)
                                <option value="{{ $agency->id }}" @selected((string) old('from_agency_id') === (string) $agency->id)>{{ $agency->name }}</option>
                            @endforeach
                        </select>
                        <x-field-error :messages="$errors->get('from_agency_id')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="to_agency_id" value="Agence d’arrivée" required />
                        <select id="to_agency_id" name="to_agency_id" required class="mt-1 block w-full rounded-lg border-slate-300">
                            <option value="">Sélectionner</option>
                            @foreach ($agencies as $agency)
                                <option value="{{ $agency->id }}" @selected((string) old('to_agency_id') === (string) $agency->id)>{{ $agency->name }}</option>
                            @endforeach
                        </select>
                        <x-field-error :messages="$errors->get('to_agency_id')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="distance_km" value="Distance en kilomètres" required />
                        <x-text-input id="distance_km" name="distance_km" type="number" min="0.001" max="10000" step="0.001" class="mt-1 block w-full" :value="old('distance_km')" required />
                        <x-field-error :messages="$errors->get('distance_km')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="source_reference" value="Référence de vérification (facultative)" />
                        <x-text-input id="source_reference" name="source_reference" type="text" maxlength="1000" class="mt-1 block w-full" :value="old('source_reference')" />
                        <x-field-error :messages="$errors->get('source_reference')" class="mt-2" />
                    </div>
                    <input type="hidden" name="source_type" value="manual_verified">
                    <input type="hidden" name="same_distance_both_ways" value="0">
                    <label class="flex items-center gap-2 text-sm text-slate-700 md:col-span-2">
                        <input type="checkbox" name="same_distance_both_ways" value="1" @checked(old('same_distance_both_ways'))>
                        Utiliser la même distance dans les deux sens
                    </label>
                    <div class="md:col-span-2"><x-primary-button>Enregistrer la distance</x-primary-button></div>
                </form>
            </x-section-card>
        @endif

        <x-section-card
            title="Distances configurées"
            description="Chaque correction renouvelle l’auteur et la date de vérification sans modifier les preuves historiques."
        >
            @if ($distances->isEmpty())
                <x-empty-state title="Aucune distance configurée" description="Renseignez chaque direction entre les agences actives." />
            @else
                <x-responsive-table label="Distances inter-agences configurées" class="shadow-none">
                    <table>
                        <thead>
                            <tr>
                                <th>Départ</th><th>Arrivée</th><th>Kilomètres</th><th>Provenance</th>
                                <th>Vérification</th><th>État</th><th><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($distances as $distance)
                                <tr>
                                    <td>{{ $distance->fromAgency?->name ?? 'Agence indisponible' }}</td>
                                    <td>{{ $distance->toAgency?->name ?? 'Agence indisponible' }}</td>
                                    <td>{{ str_replace('.', ',', (string) $distance->distance_km) }} km</td>
                                    <td>
                                        <span class="block">{{ $distance->source_type->label() }}</span>
                                        <span class="text-xs text-slate-500">{{ $distance->source_reference ?: 'Aucune référence fournie' }}</span>
                                    </td>
                                    <td>
                                        <span class="block">{{ $distance->verifier?->name ?? 'Utilisateur indisponible' }}</span>
                                        <span class="text-xs text-slate-500">{{ App\Support\Ui\UiLabel::dateTime($distance->verified_at) }}</span>
                                    </td>
                                    <td><x-status-badge :value="$distance->active ? 'active' : 'inactive'" /></td>
                                    <td class="space-y-2 text-right">
                                        @can('update', $distance)
                                            <details class="text-left">
                                                <summary class="cursor-pointer text-sm font-medium text-blue-700">Corriger</summary>
                                                <form method="POST" action="{{ route('agency-distances.update', $distance) }}" class="mt-3 min-w-72 space-y-3 rounded-lg bg-slate-50 p-3">
                                                    @csrf
                                                    @method('PATCH')
                                                    <div>
                                                        <x-input-label :for="'distance_km_'.$distance->id" value="Distance en kilomètres" required />
                                                        <x-text-input :id="'distance_km_'.$distance->id" name="distance_km" type="number" min="0.001" max="10000" step="0.001" class="mt-1 block w-full" :value="$distance->distance_km" required />
                                                    </div>
                                                    <div>
                                                        <x-input-label :for="'source_reference_'.$distance->id" value="Référence de vérification" />
                                                        <x-text-input :id="'source_reference_'.$distance->id" name="source_reference" type="text" maxlength="1000" class="mt-1 block w-full" :value="$distance->source_reference" />
                                                    </div>
                                                    <input type="hidden" name="source_type" value="manual_verified">
                                                    <input type="hidden" name="same_distance_both_ways" value="0">
                                                    <label class="flex items-center gap-2 text-xs text-slate-700">
                                                        <input type="checkbox" name="same_distance_both_ways" value="1">
                                                        Appliquer aussi au sens inverse
                                                    </label>
                                                    <x-primary-button>Enregistrer la correction</x-primary-button>
                                                </form>
                                            </details>
                                            <form method="POST" action="{{ $distance->active ? route('agency-distances.deactivate', $distance) : route('agency-distances.activate', $distance) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="text-sm font-medium {{ $distance->active ? 'text-amber-700' : 'text-emerald-700' }}">
                                                    {{ $distance->active ? 'Désactiver' : 'Activer' }}
                                                </button>
                                            </form>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-responsive-table>
            @endif
        </x-section-card>
    </div>
</x-app-layout>
