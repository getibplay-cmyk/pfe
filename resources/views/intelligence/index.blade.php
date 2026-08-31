<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header
            title="Aide à la décision"
            eyebrow="Analyses et prévisions"
            description="Consultez les prévisions et suggestions disponibles dans les agences auxquelles vous avez accès. Toutes les décisions restent sous contrôle humain."
        />

        <x-section-card
            title="Prévision de demande D+1 à D+7"
            description="Anticipez les départs des sept prochains jours pour mieux préparer les véhicules et les équipes."
        >
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="max-w-3xl text-sm leading-6 text-slate-600">Consultez une estimation centrale, un scénario prudent et les principaux éléments qui influencent la demande. La décision finale reste humaine.</p>
                <a href="{{ route('intelligence.demand-forecasts.index') }}" class="rf-button-secondary"><x-icon name="launch" size="xs" />Ouvrir les prévisions de demande</a>
            </div>
        </x-section-card>

        <x-section-card
            title="Couleur suggérée"
            description="Chargez une photo du véhicule et obtenez une couleur proposée, que vous pouvez confirmer ou corriger."
        >
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="max-w-3xl text-sm leading-6 text-slate-600">La photo reste privée et la couleur enregistrée du véhicule n’est jamais modifiée sans votre confirmation.</p>
                <a href="{{ route('intelligence.vehicle-colors.index') }}" class="rf-button-secondary"><x-icon name="launch" size="xs" />Ouvrir l’analyse couleur</a>
            </div>
        </x-section-card>

        <x-section-card
            title="Analyse des dommages"
            description="Repérez les zones à vérifier sur une photo d’inspection de retour."
        >
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="max-w-3xl text-sm leading-6 text-slate-600">Chaque zone proposée doit être vérifiée. Aucun dommage, frais ou responsable n’est déterminé automatiquement.</p>
                <a href="{{ route('intelligence.vehicle-damages.index') }}" class="rf-button-secondary"><x-icon name="launch" size="xs" />Ouvrir l’assistant dommages</a>
            </div>
        </x-section-card>

        <x-section-card
            title="Immatriculation détectée"
            description="Utilisez une photo complète du véhicule ou une photo rapprochée de la plaque pour faciliter la saisie."
        >
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="max-w-3xl text-sm leading-6 text-slate-600">La proposition doit être confirmée ou corrigée avant toute utilisation dans la fiche véhicule.</p>
                <a href="{{ route('intelligence.vehicle-plates.index') }}" class="rf-button-secondary"><x-icon name="launch" size="xs" />Ouvrir la revue des plaques</a>
            </div>
        </x-section-card>

        <x-section-card
            title="Usages atypiques"
            description="Repérez les dossiers qui méritent une vérification complémentaire."
        >
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="max-w-3xl text-sm leading-6 text-slate-600">Un signal atypique attire l’attention d’un responsable ; il ne prouve ni fraude, faute, dommage ou responsabilité.</p>
                <a href="{{ route('intelligence.rental-usage-anomalies.index') }}" class="rf-button-secondary"><x-icon name="launch" size="xs" />Ouvrir les usages atypiques</a>
            </div>
        </x-section-card>

        <x-section-card title="Principes d’utilisation" description="Les fonctionnalités intelligentes assistent les équipes sans prendre de décision à leur place.">
            <ul class="list-disc space-y-2 pl-5 text-sm leading-6 text-slate-700">
                <li>Chaque résultat doit être vérifié par un utilisateur autorisé.</li>
                <li>Aucune suggestion ne modifie automatiquement un véhicule, une réservation ou un contrat.</li>
                <li>Les données préparées pour l’analyse excluent les identités et coordonnées des clients.</li>
                <li>Une anomalie ne prouve ni fraude, danger, dommage, faute ou responsabilité.</li>
                <li>Chaque traitement est lancé explicitement depuis son écran métier.</li>
            </ul>
        </x-section-card>

        <x-section-card title="Protection des données">
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <span class="font-medium text-slate-700">Préparation des données :</span>
                <span class="rounded-full px-3 py-1 font-semibold {{ $configured ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900' }}">
                    {{ $configured ? 'Configurée' : 'Configuration requise' }}
                </span>
                <span class="text-slate-500">Les exports destinés aux analyses sont anonymisés et conservés dans le stockage privé.</span>
            </div>
        </x-section-card>

        <x-section-card title="Fonctionnalités en préparation" description="Les services indisponibles ne sont pas proposés dans les parcours métier.">
            <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm leading-6 text-blue-950">
                Leur activation dépend de la configuration de la plateforme et des autorisations de votre entreprise. Contactez l’administrateur si une fonctionnalité attendue n’apparaît pas.
            </div>
        </x-section-card>

        <x-section-card
            title="Démonstrations isolées"
            description="Ces exemples permettent de découvrir le fonctionnement sans utiliser de données réelles ni modifier l’exploitation."
        >
            <div class="grid gap-3 text-sm md:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-slate-200 p-3">
                    <p class="text-slate-500">État effectif</p>
                    <p class="mt-1 font-semibold {{ $contractDemo['enabled'] ? 'text-emerald-700' : 'text-slate-700' }}">
                        {{ $contractDemo['enabled'] ? 'Démonstration isolée active' : 'Désactivé par défaut' }}
                    </p>
                </div>
                <div class="rounded-xl border border-slate-200 p-3">
                    <p class="text-slate-500">Exemples disponibles</p>
                    <p class="mt-1 font-semibold">{{ App\Support\Ui\BusinessNumber::integer($contractDemo['contract_count']) }} démonstrations</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-3">
                    <p class="text-slate-500">Données réelles</p>
                    <p class="mt-1 font-semibold text-amber-800">Aucune</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-3">
                    <p class="text-slate-500">Effet autorisé</p>
                    <p class="mt-1 font-semibold">Aucune action métier</p>
                </div>
            </div>
            <p class="mt-4 text-sm leading-6 text-slate-600">Les démonstrations restent séparées des véhicules, réservations et contrats de votre entreprise.</p>
            @if ($contractDemo['enabled'])
                <div class="mt-4"><a href="{{ route('intelligence.contract-demo.index') }}" class="rf-button-secondary"><x-icon name="launch" size="xs" />Ouvrir la démonstration isolée</a></div>
            @endif
        </x-section-card>

        <x-filter-panel title="Période des données anonymisées">
            <form method="GET" action="{{ route('intelligence.export') }}" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4" data-no-global-loading="true">
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
                title="Exports anonymisés"
                description="Chaque fichier est conservé dans le stockage privé et peut être téléchargé par un utilisateur autorisé."
            >
                <x-responsive-table label="Historique des exports anonymisés" class="shadow-none">
                    <table>
                        <thead>
                            <tr>
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
                                    <td>{{ $run->date_from->format('d/m/Y') }} → {{ $run->date_to->format('d/m/Y') }}</td>
                                    <td>{{ $run->scope_kind === 'agency' ? 'Agence autorisée' : 'Entreprise entière' }}</td>
                                    <td>{{ App\Support\Ui\BusinessNumber::integer($run->row_count) }}</td>
                                    <td>{{ App\Support\Ui\UiLabel::dateTime($run->created_at) }}</td>
                                    <td class="text-right">
                                        <div class="flex flex-wrap justify-end gap-3">
                                            <a href="{{ route('intelligence.exports.manifest', $run) }}" class="font-medium text-indigo-700" data-no-global-loading="true">Informations de contrôle</a>
                                            <a href="{{ route('intelligence.exports.download', $run) }}" class="font-medium text-indigo-700" data-no-global-loading="true">Télécharger le CSV</a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="p-10 text-center text-slate-500">Aucun export n’a encore été créé pour ce périmètre.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-responsive-table>
                <p class="mt-4 text-xs leading-5 text-slate-500">Les informations de contrôle permettent de vérifier le fichier sans exposer son emplacement privé.</p>
            </x-section-card>
        @endif

        <x-section-card
            title="Résultats de démonstration"
            description="Consultez et vérifiez des exemples isolés, sans effet sur les données métier."
        >
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="max-w-3xl text-sm leading-6 text-slate-600">
                    Ces exemples ne contiennent aucune identité, coordonnée ou action automatique. Toute décision reste humaine.
                </p>
                <a href="{{ route('intelligence.result-batches.index') }}" class="rf-button-secondary"><x-icon name="launch" size="xs" />Ouvrir les résultats de démonstration</a>
            </div>
        </x-section-card>

        <x-section-card
            title="Suggestions de réallocation"
            description="Préparez un meilleur équilibre de la flotte entre agences, puis vérifiez chaque déplacement proposé."
        >
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="max-w-3xl text-sm leading-6 text-slate-600">
                    Les distances, les coûts estimés et la disponibilité sont contrôlés avant l’affichage. Accepter une suggestion ne déplace aucun véhicule automatiquement.
                </p>
                @if (auth()->user()->agency_id === null)
                    <a href="{{ route('intelligence.fleet-reallocation.index') }}" class="rf-button-secondary"><x-icon name="launch" size="xs" />Ouvrir les suggestions</a>
                @else
                    <span class="text-sm text-slate-500">Registre réservé à la vue entreprise entière.</span>
                @endif
            </div>
        </x-section-card>

        <x-empty-state
            title="Aucune action automatique"
            description="Les prévisions et suggestions restent consultatives. Toute interprétation et toute décision relèvent d’un utilisateur autorisé."
        />
    </div>
</x-app-layout>
