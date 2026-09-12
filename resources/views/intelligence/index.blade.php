<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header
            :title="__('Aide à la décision')"
            :eyebrow="__('Analyses et prévisions')"
            :description="__('Consultez les prévisions et suggestions disponibles dans les agences auxquelles vous avez accès. Toutes les décisions restent sous contrôle humain.')"
        />

        <x-section-card
            :title="__('Prévision de demande D+1 à D+7')"
            :description="__('Anticipez les départs des sept prochains jours pour mieux préparer les véhicules et les équipes.')"
        >
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="max-w-3xl text-sm leading-6 text-slate-600">{{ __('Consultez une estimation centrale, un scénario prudent et les principaux éléments qui influencent la demande. La décision finale reste humaine.') }}</p>
                <a href="{{ route('intelligence.demand-forecasts.index') }}" class="rf-button-secondary"><x-icon name="launch" size="xs" />{{ __('Ouvrir les prévisions de demande') }}</a>
            </div>
        </x-section-card>

        <x-section-card
            :title="__('Couleur suggérée')"
            :description="__('Chargez une photo du véhicule et obtenez une couleur proposée, que vous pouvez confirmer ou corriger.')"
        >
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="max-w-3xl text-sm leading-6 text-slate-600">{{ __('La photo reste privée et la couleur enregistrée du véhicule n’est jamais modifiée sans votre confirmation.') }}</p>
                <a href="{{ route('intelligence.vehicle-colors.index') }}" class="rf-button-secondary"><x-icon name="launch" size="xs" />{{ __('Ouvrir l’analyse couleur') }}</a>
            </div>
        </x-section-card>

        <x-section-card
            :title="__('Analyse des dommages')"
            :description="__('Repérez les zones à vérifier sur une photo d’inspection de retour.')"
        >
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="max-w-3xl text-sm leading-6 text-slate-600">{{ __('Chaque zone proposée doit être vérifiée. Aucun dommage, frais ou responsable n’est déterminé automatiquement.') }}</p>
                <a href="{{ route('intelligence.vehicle-damages.index') }}" class="rf-button-secondary"><x-icon name="launch" size="xs" />{{ __('Ouvrir l’assistant dommages') }}</a>
            </div>
        </x-section-card>

        <x-section-card
            :title="__('Immatriculation détectée')"
            :description="__('Utilisez une photo complète du véhicule ou une photo rapprochée de la plaque pour faciliter la saisie.')"
        >
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="max-w-3xl text-sm leading-6 text-slate-600">{{ __('La proposition doit être confirmée ou corrigée avant toute utilisation dans la fiche véhicule.') }}</p>
                <a href="{{ route('intelligence.vehicle-plates.index') }}" class="rf-button-secondary"><x-icon name="launch" size="xs" />{{ __('Ouvrir la revue des plaques') }}</a>
            </div>
        </x-section-card>

        <x-section-card
            :title="__('Usages atypiques')"
            :description="__('Repérez les dossiers qui méritent une vérification complémentaire.')"
        >
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="max-w-3xl text-sm leading-6 text-slate-600">{{ __('Un signal atypique attire l’attention d’un responsable ; il ne prouve ni fraude, faute, dommage ou responsabilité.') }}</p>
                <a href="{{ route('intelligence.rental-usage-anomalies.index') }}" class="rf-button-secondary"><x-icon name="launch" size="xs" />{{ __('Ouvrir les usages atypiques') }}</a>
            </div>
        </x-section-card>

        <x-section-card :title="__('Principes d’utilisation')" :description="__('Les fonctionnalités intelligentes assistent les équipes sans prendre de décision à leur place.')">
            <ul class="list-disc space-y-2 ps-5 text-sm leading-6 text-slate-700">
                <li>{{ __('Chaque résultat doit être vérifié par un utilisateur autorisé.') }}</li>
                <li>{{ __('Aucune suggestion ne modifie automatiquement un véhicule, une réservation ou un contrat.') }}</li>
                <li>{{ __('Les données préparées pour l’analyse excluent les identités et coordonnées des clients.') }}</li>
                <li>{{ __('Une anomalie ne prouve ni fraude, danger, dommage, faute ou responsabilité.') }}</li>
                <li>{{ __('Chaque traitement est lancé explicitement depuis son écran métier.') }}</li>
            </ul>
        </x-section-card>

        <x-section-card :title="__('Protection des données')">
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <span class="font-medium text-slate-700">{{ __('Préparation des données :') }}</span>
                <span class="rounded-full px-3 py-1 font-semibold {{ $configured ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900' }}">
                    {{ $configured ? __('Configurée') : __('Configuration requise') }}
                </span>
                <span class="text-slate-500">{{ __('Les exports destinés aux analyses sont anonymisés et conservés dans le stockage privé.') }}</span>
            </div>
        </x-section-card>

        <x-section-card :title="__('Fonctionnalités en préparation')" :description="__('Les services indisponibles ne sont pas proposés dans les parcours métier.')">
            <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm leading-6 text-blue-950">
                {{ __('Leur activation dépend de la configuration de la plateforme et des autorisations de votre entreprise. Contactez l’administrateur si une fonctionnalité attendue n’apparaît pas.') }}
            </div>
        </x-section-card>

        <x-section-card
            :title="__('Démonstrations isolées')"
            :description="__('Ces exemples permettent de découvrir le fonctionnement sans utiliser de données réelles ni modifier l’exploitation.')"
        >
            <div class="grid gap-3 text-sm md:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-slate-200 p-3">
                    <p class="text-slate-500">{{ __('État effectif') }}</p>
                    <p class="mt-1 font-semibold {{ $contractDemo['enabled'] ? 'text-emerald-700' : 'text-slate-700' }}">
                        {{ $contractDemo['enabled'] ? __('Démonstration isolée active') : __('Désactivé par défaut') }}
                    </p>
                </div>
                <div class="rounded-xl border border-slate-200 p-3">
                    <p class="text-slate-500">{{ __('Exemples disponibles') }}</p>
                    <p class="mt-1 font-semibold">{{ App\Support\Ui\BusinessNumber::integer($contractDemo['contract_count']) }} {{ __('démonstrations') }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-3">
                    <p class="text-slate-500">{{ __('Données réelles') }}</p>
                    <p class="mt-1 font-semibold text-amber-800">{{ __('Aucune') }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-3">
                    <p class="text-slate-500">{{ __('Effet autorisé') }}</p>
                    <p class="mt-1 font-semibold">{{ __('Aucune action métier') }}</p>
                </div>
            </div>
            <p class="mt-4 text-sm leading-6 text-slate-600">{{ __('Les démonstrations restent séparées des véhicules, réservations et contrats de votre entreprise.') }}</p>
            @if ($contractDemo['enabled'])
                <div class="mt-4"><a href="{{ route('intelligence.contract-demo.index') }}" class="rf-button-secondary"><x-icon name="launch" size="xs" />{{ __('Ouvrir la démonstration isolée') }}</a></div>
            @endif
        </x-section-card>

        <x-filter-panel :title="__('Période des données anonymisées')">
            <form method="GET" action="{{ route('intelligence.export') }}" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4" data-no-global-loading="true">
                <div>
                    <x-input-label for="intelligence-date-from" :value="__('Du')" />
                    <x-text-input id="intelligence-date-from" name="date_from" type="date" class="mt-1 block w-full" :value="$filters['date_from']" required />
                    <x-field-error :messages="$errors->get('date_from')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="intelligence-date-to" :value="__('Au')" />
                    <x-text-input id="intelligence-date-to" name="date_to" type="date" class="mt-1 block w-full" :value="$filters['date_to']" required />
                    <x-field-error :messages="$errors->get('date_to')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="intelligence-agency" :value="__('Agence')" />
                    <select id="intelligence-agency" name="agency_id" class="mt-1 block w-full rounded-lg border-slate-300">
                        @if ($agencies->count() > 1)<option value="">{{ __('Toutes les agences autorisées') }}</option>@endif
                        @foreach ($agencies as $agency)
                            <option value="{{ $agency->id }}" @selected(($filters['agency_id'] ?? null) == $agency->id)>{{ $agency->name }}</option>
                        @endforeach
                    </select>
                    <x-field-error :messages="$errors->get('agency_id')" class="mt-2" />
                </div>
                <div class="flex items-end">
                    @if (auth()->user()->hasPermission('prediction.export'))
                        <x-primary-button class="w-full justify-center" :disabled="! $configured">{{ __('Exporter le CSV anonymisé') }}</x-primary-button>
                    @else
                        <p class="text-sm text-slate-600">{{ __('Votre rôle autorise la consultation, pas l’export.') }}</p>
                    @endif
                </div>
            </form>
        </x-filter-panel>

        @if (auth()->user()->hasPermission('prediction.export'))
            <x-section-card
                :title="__('Exports anonymisés')"
                :description="__('Chaque fichier est conservé dans le stockage privé et peut être téléchargé par un utilisateur autorisé.')"
            >
                <x-responsive-table :label="__('Historique des exports anonymisés')" class="shadow-none">
                    <table>
                        <thead>
                            <tr>
                                <th>{{ __('Période') }}</th>
                                <th>{{ __('Périmètre') }}</th>
                                <th>{{ __('Lignes') }}</th>
                                <th>{{ __('Créé le') }}</th>
                                <th><span class="sr-only">{{ __('Téléchargements') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($exportRuns as $run)
                                <tr>
                                    <td>{{ $run->date_from->format('d/m/Y') }} → {{ $run->date_to->format('d/m/Y') }}</td>
                                    <td>{{ $run->scope_kind === 'agency' ? __('Agence autorisée') : __('Entreprise entière') }}</td>
                                    <td>{{ App\Support\Ui\BusinessNumber::integer($run->row_count) }}</td>
                                    <td>{{ App\Support\Ui\UiLabel::dateTime($run->created_at) }}</td>
                                    <td class="text-end">
                                        <div class="flex flex-wrap justify-end gap-3">
                                            <a href="{{ route('intelligence.exports.manifest', $run) }}" class="font-medium text-indigo-700" data-no-global-loading="true">{{ __('Informations de contrôle') }}</a>
                                            <a href="{{ route('intelligence.exports.download', $run) }}" class="font-medium text-indigo-700" data-no-global-loading="true">{{ __('Télécharger le CSV') }}</a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="p-10 text-center text-slate-500">{{ __('Aucun export n’a encore été créé pour ce périmètre.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-responsive-table>
                <p class="mt-4 text-xs leading-5 text-slate-500">{{ __('Les informations de contrôle permettent de vérifier le fichier sans exposer son emplacement privé.') }}</p>
            </x-section-card>
        @endif

        <x-section-card
            :title="__('Résultats de démonstration')"
            :description="__('Consultez et vérifiez des exemples isolés, sans effet sur les données métier.')"
        >
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="max-w-3xl text-sm leading-6 text-slate-600">
                    {{ __('Ces exemples ne contiennent aucune identité, coordonnée ou action automatique. Toute décision reste humaine.') }}
                </p>
                <a href="{{ route('intelligence.result-batches.index') }}" class="rf-button-secondary"><x-icon name="launch" size="xs" />{{ __('Ouvrir les résultats de démonstration') }}</a>
            </div>
        </x-section-card>

        <x-section-card
            :title="__('Suggestions de réallocation')"
            :description="__('Préparez un meilleur équilibre de la flotte entre agences, puis vérifiez chaque déplacement proposé.')"
        >
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="max-w-3xl text-sm leading-6 text-slate-600">
                    {{ __('Les distances, les coûts estimés et la disponibilité sont contrôlés avant l’affichage. Accepter une suggestion ne déplace aucun véhicule automatiquement.') }}
                </p>
                @if (auth()->user()->agency_id === null)
                    <a href="{{ route('intelligence.fleet-reallocation.index') }}" class="rf-button-secondary"><x-icon name="launch" size="xs" />{{ __('Ouvrir les suggestions') }}</a>
                @else
                    <span class="text-sm text-slate-500">{{ __('Registre réservé à la vue entreprise entière.') }}</span>
                @endif
            </div>
        </x-section-card>

        <x-empty-state
            :title="__('Aucune action automatique')"
            :description="__('Les prévisions et suggestions restent consultatives. Toute interprétation et toute décision relèvent d’un utilisateur autorisé.')"
        />
    </div>
</x-app-layout>
