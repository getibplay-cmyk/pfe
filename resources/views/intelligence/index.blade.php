<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header
            title="Intelligence et export anonymisé"
            eyebrow="Pilotage"
            description="Préparez un dataset réel tenant/agence-scopé pour le modèle d’anomalies, sans afficher ni importer de prédiction."
        />

        <x-section-card title="Cadre scientifique et humain" description="Le modèle et la baseline n’exécutent aucune décision métier.">
            <ul class="list-disc space-y-2 pl-5 text-sm leading-6 text-slate-700">
                <li>Le modèle <span class="font-medium">rental_anomaly_iforest 0.1.0</span> a été validé sur des données synthétiques et reste gelé.</li>
                <li>L’export réel v1.1 ne contient aucune étiquette, cible, identité ou décision humaine.</li>
                <li>Une anomalie sert uniquement à prioriser une revue humaine ; elle ne prouve ni fraude ni responsabilité.</li>
                <li>Les calculs et l’entraînement utilisent le CPU ; aucun GPU n’est requis.</li>
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

        <x-empty-state
            title="Aucune prédiction affichée dans ce sous-lot"
            description="Le Lot 07B1 prépare uniquement le contrat d’intégration, la baseline explicable et l’export réel anonymisé. L’import et la revue des prédictions appartiennent au Lot 08."
        />
    </div>
</x-app-layout>
