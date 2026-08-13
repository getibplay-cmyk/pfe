<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header
            title="Intelligence et export anonymisé"
            eyebrow="Pilotage"
            description="Préparez un dataset réel tenant/agence-scopé et consultez les preuves scientifiques gelées, sans exécuter ni importer de prédiction."
        />

        <x-section-card title="Cadre scientifique et humain" description="Les preuves disponibles n’exécutent aucune décision métier.">
            <ul class="list-disc space-y-2 pl-5 text-sm leading-6 text-slate-700">
                <li><span class="font-medium">rental_anomaly_iforest 0.1.0</span> est un artefact synthétique historique du Lot 07B1, distinct du candidat public J9.</li>
                <li>Le benchmark public J9 a sélectionné <span class="font-medium">robust_mad_top2</span>, sans validation locale RentFleet et sans autorisation d’usage dans J13.</li>
                <li>L’export réel v1.1 ne contient aucune étiquette, cible, identité ou décision humaine.</li>
                <li>Une anomalie ne prouve ni fraude, danger, dommage, faute ou responsabilité.</li>
                <li>Aucune inférence, aucun entraînement, solveur ou calcul de modèle n’est exécuté par cet écran.</li>
            </ul>
        </x-section-card>

        <x-section-card title="Configuration de l’export">
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <span class="font-medium text-slate-700">Clé de pseudonymisation :</span>
                <span class="rounded-full px-3 py-1 font-semibold {{ $configured ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900' }}">
                    {{ $configured ? 'Configurée' : 'Configuration requise' }}
                </span>
                <span class="text-slate-500">La valeur du secret n’est jamais affichée, auditée ou transmise au navigateur.</span>
            </div>
        </x-section-card>

        <x-section-card
            title="J13 · preuves consultatives désactivées"
            description="Quatre cartes en lecture seule exposent la provenance et les limites gelées ; elles ne constituent pas des sorties de modèles RentFleet."
        >
            <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950">
                <p class="font-semibold">Mode consultatif fermé</p>
                <p class="mt-1 leading-6">
                    Feature flags désactivés, SaaS et production interdits. Toute future utilisation exigerait une décision humaine auditée avec l’effet constant
                    <code class="font-semibold">{{ $consultativeGate['decision_effect'] }}</code>.
                </p>
            </div>

            <div class="mt-5 grid gap-4 lg:grid-cols-2">
                @foreach ($consultativeModules as $module)
                    <article data-j13-module="{{ $module['id'] }}" aria-labelledby="j13-module-{{ $module['id'] }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $module['authoritative_stage'] }} · audit {{ $module['audit_score'] }}</p>
                                <h3 id="j13-module-{{ $module['id'] }}" class="mt-1 text-lg font-semibold text-slate-950">{{ $module['label'] }}</h3>
                            </div>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $module['benchmark_gate_passed'] ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900' }}">
                                {{ $module['benchmark_gate_passed'] ? 'Gate du benchmark proxy franchie' : 'Gate du benchmark proxy non franchie' }}
                            </span>
                        </div>

                        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="font-medium text-slate-500">Niveau de preuve</dt>
                                <dd class="mt-1 font-semibold text-slate-800">{{ $module['evidence_label'] }}</dd>
                            </div>
                            <div>
                                <dt class="font-medium text-slate-500">Rôle du benchmark</dt>
                                <dd class="mt-1 break-words font-mono text-xs text-slate-700">{{ $module['benchmark_role'] }}</dd>
                            </div>
                            <div class="sm:col-span-2">
                                <dt class="font-medium text-slate-500">Décision gelée</dt>
                                <dd class="mt-1 break-words font-mono text-xs text-slate-700">{{ $module['gate_decision'] }}</dd>
                            </div>
                        </dl>

                        <div class="mt-4 rounded-xl bg-slate-50 p-4">
                            <p class="text-sm font-semibold text-slate-800">Affirmation autorisée</p>
                            <p lang="en" class="mt-2 text-sm leading-6 text-slate-700">{{ $module['claim_allowed'] }}</p>
                            <p lang="en" class="mt-2 text-xs leading-5 text-slate-500">{{ $module['evidence_description'] }}</p>
                        </div>

                        <div class="mt-4">
                            <p class="text-sm font-semibold text-slate-800">Limites scientifiques</p>
                            <ul lang="en" class="mt-2 list-disc space-y-1 pl-5 text-sm leading-6 text-slate-600">
                                @foreach ($module['claims_forbidden'] as $claim)
                                    <li>{{ $claim }}</li>
                                @endforeach
                            </ul>
                        </div>

                        <p class="mt-4 border-t border-slate-200 pt-3 text-xs font-semibold text-slate-600">
                            Feature flag : désactivé · SaaS : non · Production : non
                        </p>
                    </article>
                @endforeach
            </div>

            <div class="mt-5">
                <h3 class="text-base font-semibold text-slate-950">Lignée distincte du module d’usages atypiques</h3>
                <div class="mt-3 grid gap-3 text-sm md:grid-cols-3">
                    <div class="rounded-xl border border-slate-200 p-4">
                        <p class="font-semibold text-slate-900">Benchmark public J9</p>
                        <p class="mt-2"><code>{{ $anomalyLineage['j9_public_proxy_benchmark']['selected_candidate'] }}</code></p>
                        <p class="mt-1 text-slate-600">{{ $anomalyLineage['j9_public_proxy_benchmark']['source'] }}</p>
                        <p class="mt-2 font-medium text-amber-800">Interdit dans J13</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 p-4">
                        <p class="font-semibold text-slate-900">Artefact historique Lot 07B1</p>
                        <p class="mt-2"><code>{{ $anomalyLineage['legacy_lot07b1_synthetic_artifact']['name'] }} {{ $anomalyLineage['legacy_lot07b1_synthetic_artifact']['version'] }}</code></p>
                        <p class="mt-1 text-slate-600">{{ $anomalyLineage['legacy_lot07b1_synthetic_artifact']['algorithm'] }} · données synthétiques</p>
                        <p class="mt-2 font-medium text-amber-800">Distinct de J9 et interdit dans J13</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 p-4">
                        <p class="font-semibold text-slate-900">Fixture contractuelle J11/J12</p>
                        <p class="mt-2 break-words"><code>{{ $anomalyLineage['j11_j12_fixture']['computation_status'] }}</code></p>
                        <p class="mt-1 text-slate-600">Aucun modèle ni solveur exécuté</p>
                        <p class="mt-2 font-medium text-slate-700">Preuve d’intégration uniquement</p>
                    </div>
                </div>
            </div>
        </x-section-card>

        <x-section-card
            title="Adaptateur contractuel J11 / J12"
            description="Quatre contrats synthétiques sont intégrés comme preuve technique, sans modèle ni effet sur l’exploitation."
        >
            <div class="grid gap-3 text-sm md:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-slate-200 p-3">
                    <p class="text-slate-500">État effectif</p>
                    <p class="mt-1 font-semibold {{ $contractDemo['enabled'] ? 'text-emerald-700' : 'text-slate-700' }}">
                        {{ $contractDemo['enabled'] ? 'Démonstration isolée active' : 'Désactivé par défaut' }}
                    </p>
                </div>
                <div class="rounded-xl border border-slate-200 p-3">
                    <p class="text-slate-500">Contrats</p>
                    <p class="mt-1 font-semibold">{{ $contractDemo['contract_count'] }} fixtures synthétiques</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-3">
                    <p class="text-slate-500">Prêt pour le SaaS</p>
                    <p class="mt-1 font-semibold text-amber-800">Non</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-3">
                    <p class="text-slate-500">Effet autorisé</p>
                    <p class="mt-1 font-semibold">Aucune action métier</p>
                </div>
            </div>
            <p class="mt-4 text-sm leading-6 text-slate-600">
                Les payloads publics Munich et Scania ne sont jamais importés comme recommandations opérationnelles. Les routes de démonstration répondent 404 tant que le verrou J12 reste fermé.
            </p>
            @if ($contractDemo['enabled'])
                <div class="mt-4"><a href="{{ route('intelligence.contract-demo.index') }}" class="rf-button-secondary">Ouvrir la démonstration isolée</a></div>
            @endif
        </x-section-card>

        <x-filter-panel title="Périmètre du dataset réel">
            <form method="GET" action="{{ route('intelligence.export') }}" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div>
                    <x-input-label for="intelligence-date-from" value="Du" />
                    <x-text-input id="intelligence-date-from" name="date_from" type="date" class="mt-1 block w-full" :value="$filters['date_from']" required />
                    <x-field-error :messages="$errors->get('date_from')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="intelligence-date-to" value="Au" />
                    <x-text-input id="intelligence-date-to" name="date_to" type="date" class="mt-1 block w-full" :value="$filters['date_to']" required />
                    <x-field-error :messages="$errors->get('date_to')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="intelligence-agency" value="Agence" />
                    <select id="intelligence-agency" name="agency_id" class="mt-1 block w-full rounded-lg border-slate-300">
                        @if ($agencies->count() > 1)<option value="">Toutes les agences autorisées</option>@endif
                        @foreach ($agencies as $agency)
                            <option value="{{ $agency->id }}" @selected(($filters['agency_id'] ?? null) == $agency->id)>{{ $agency->name }}</option>
                        @endforeach
                    </select>
                    <x-field-error :messages="$errors->get('agency_id')" class="mt-2" />
                </div>
                <div class="flex items-end">
                    @if (auth()->user()->hasPermission('prediction.export'))
                        <x-primary-button class="w-full justify-center" :disabled="! $configured">Exporter le CSV anonymisé</x-primary-button>
                    @else
                        <p class="text-sm text-slate-600">Votre rôle autorise la consultation, pas l’export.</p>
                    @endif
                </div>
            </form>
        </x-filter-panel>

        @if (auth()->user()->hasPermission('prediction.export'))
            <x-section-card
                title="J14-A · snapshots d’export reproductibles"
                description="Chaque CSV est conservé sur le disque privé avec un identifiant d’exécution et un manifeste d’intégrité, sans prédiction ni effet métier."
            >
                <x-responsive-table label="Registre append-only des snapshots Intelligence" class="shadow-none">
                    <table>
                        <thead>
                            <tr>
                                <th>Exécution</th>
                                <th>Période</th>
                                <th>Périmètre</th>
                                <th>Lignes</th>
                                <th>Créé le</th>
                                <th><span class="sr-only">Téléchargements</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($exportRuns as $run)
                                <tr>
                                    <td class="font-mono text-xs">{{ $run->run_id }}</td>
                                    <td>{{ $run->date_from->format('d/m/Y') }} → {{ $run->date_to->format('d/m/Y') }}</td>
                                    <td>{{ $run->scope_kind === 'agency' ? 'Agence autorisée' : 'Entreprise entière' }}</td>
                                    <td>{{ number_format($run->row_count, 0, ',', ' ') }}</td>
                                    <td>{{ App\Support\Ui\UiLabel::dateTime($run->created_at) }}</td>
                                    <td class="text-right">
                                        <div class="flex flex-wrap justify-end gap-3">
                                            <a href="{{ route('intelligence.exports.manifest', $run) }}" class="font-medium text-indigo-700">Manifeste JSON</a>
                                            <a href="{{ route('intelligence.exports.download', $run) }}" class="font-medium text-indigo-700">Snapshot CSV</a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="p-10 text-center text-slate-500">Aucun snapshot n’a encore été créé pour ce périmètre.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-responsive-table>
                <p class="mt-4 text-xs leading-5 text-slate-500">
                    L’empreinte SHA-256 complète et les versions autorisées figurent dans le manifeste. Le chemin du fichier privé n’est jamais exposé.
                </p>
            </x-section-card>
        @endif

        <x-section-card
            title="J14-B · lots de résultats synthétiques"
            description="Import fermé, lignée exacte vers J14-A, idempotence et décision humaine append-only, toujours sans effet métier."
        >
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="max-w-3xl text-sm leading-6 text-slate-600">
                    La revue J14-B n’accepte que des sorties qualitatives de fixture synthétique. Elle refuse tout score, identifiant direct, coordonnée, action automatique ou payload non documenté.
                </p>
                <a href="{{ route('intelligence.result-batches.index') }}" class="rf-button-secondary">Ouvrir le registre J14-B</a>
            </div>
        </x-section-card>

        <x-empty-state
            title="Aucune prédiction exécutée ou affichée"
            description="J13 et J14 exposent uniquement des preuves synthétiques ou des limites gelées. Aucune recommandation opérationnelle n’est activée."
        />
    </div>
</x-app-layout>
