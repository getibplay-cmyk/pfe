
<x-app-layout>
    @php
        $departure = $contract->inspections->firstWhere('inspection_type.value', 'departure');
        $return = $contract->inspections->firstWhere('inspection_type.value', 'return');
        $damageAssistantConfiguration = [
            'ready' => $damageAssistant['ready'],
            'storeUrl' => $damageAssistant['store_url'],
            'initialNotes' => old('notes', ''),
        ];
    @endphp
    <div class="rf-page">
        <x-page-header :title="$contract->contract_number" :eyebrow="__('Contrat de location')" :description="__('Cycle contractuel, documents et situation financière dans la devise du contrat.')" :breadcrumbs="[['label' => __('Contrats'), 'url' => route('contracts.index')], ['label' => $contract->contract_number]]">
            <x-slot:actions>
                <x-status-badge :value="$contract->status" />
                <a href="{{ route('contracts.index') }}" class="rf-button-secondary"><x-icon name="previous" size="xs" />{{ __('Retour aux contrats') }}</a>
                <x-icon-button icon="view" :label="__('Ouvrir l’aperçu du contrat')" :href="route('contracts.print', $contract)" target="_blank" rel="noopener" />
                <x-icon-button icon="print" :label="__('Imprimer le contrat')" :href="route('contracts.print', ['contract' => $contract, 'print' => 1])" variant="primary" target="_blank" rel="noopener" />
            </x-slot:actions>
        </x-page-header>
        @if ($contractDocument['historical'])
            <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950" role="status">
                {{ __('Version historique : seules les informations et clauses réellement figées à sa création sont affichées. Les nouvelles conditions bilingues ne lui sont pas attribuées rétroactivement.') }}
            </div>
        @else
            <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950" role="note">
                {{ __('Modèle contractuel générique RentFleet, version') }} {{ $contractDocument['template_version'] }}{{ __('. L’entreprise doit le personnaliser et le faire valider avant tout usage de production.') }}
            </div>
        @endif
        <x-form-errors />
        @include('contracts.partials.workflow-status')
        @if ($usageAnomaly)
            <x-section-card :title="__('Usage atypique à vérifier')" :description="__('Résumé consultatif du retour de location.')">
                <p class="text-sm font-semibold text-slate-950">{{ $usageAnomaly->reviewStatusLabel() }}</p>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Cet indicateur statistique oriente uniquement une vérification humaine et ne déclenche aucun changement automatique.') }}</p>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    @foreach (array_slice($usageAnomaly->primary_factors, 0, 2) as $factor)
                        <div class="rounded-xl border border-slate-200 p-3 text-sm">
                            <p class="text-slate-500">{{ App\Support\Intelligence\RentalUsageAnomaly\RentalUsageAnomalyContract::featureLabel($factor['feature']) }}</p>
                            <p class="mt-1 font-semibold">
                                {{ App\Support\Ui\BusinessNumber::average($factor['value'], 2, 2) }}
                                {{ App\Support\Intelligence\RentalUsageAnomaly\RentalUsageAnomalyContract::featureUnit($factor['feature']) }}
                            </p>
                        </div>
                    @endforeach
                </div>
                @can('viewAny', App\Models\RentalUsageAnomalyRun::class)
                    <a
                        href="{{ route('intelligence.rental-usage-anomalies.index', ['agency' => $contract->agency_id, 'date_from' => $usageAnomaly->event_at->timezone((string) config('app.timezone'))->toDateString(), 'date_to' => $usageAnomaly->event_at->timezone((string) config('app.timezone'))->toDateString()]) }}"
                        class="rf-button-link mt-4"
                    >{{ __('Ouvrir la file de vérification') }}</a>
                @endcan
            </x-section-card>
        @endif
        <div class="grid gap-6 lg:grid-cols-3">
            <x-section-card :title="__('Synthèse')" class="lg:col-span-2"><x-metadata-list><x-metadata-item :label="__('Réservation')"><a href="{{ route('reservations.show', $contract->reservation) }}">{{ $contract->reservation->reservation_number }}</a></x-metadata-item><x-metadata-item :label="__('Client')">{{ $contract->customer->displayName() }}</x-metadata-item><x-metadata-item :label="__('Véhicule')">{{ $contract->vehicle->registration_number }}</x-metadata-item><x-metadata-item :label="__('Conducteur principal')">{{ optional($contract->drivers->firstWhere('is_primary', true)?->driver)->first_name }} {{ optional($contract->drivers->firstWhere('is_primary', true)?->driver)->last_name }}</x-metadata-item><x-metadata-item :label="__('Période')">{{ App\Support\Ui\UiLabel::dateTime($contract->expected_start_at) }} — {{ App\Support\Ui\UiLabel::dateTime($contract->expected_return_at) }}</x-metadata-item><x-metadata-item :label="__('Location')">{{ App\Support\Ui\UiLabel::money($contract->rental_subtotal, $contract->currency) }}</x-metadata-item><x-metadata-item :label="__('Frais approuvés')">{{ App\Support\Ui\UiLabel::money($contract->additional_charges_total, $contract->currency) }}</x-metadata-item><x-metadata-item :label="__('Total')"><strong>{{ App\Support\Ui\UiLabel::money($contract->total_amount, $contract->currency) }}</strong></x-metadata-item></x-metadata-list></x-section-card>
            <x-section-card :title="__('Version courante')" :description="__('Les empreintes techniques sont contrôlées sans être exposées dans l’interface.')">
                <p class="text-sm font-medium">{{ __('Version') }} {{ $contract->currentVersion?->version_number ?? '—' }}</p>
                <p class="mt-2 text-xs">{{ $contract->currentVersion?->locked_at ? __('Acceptation enregistrée — version protégée') : __('Non acceptée — toute modification crée une nouvelle version') }}</p>
                @if (! $contractDocument['historical'])
                    <p class="mt-2 text-xs text-slate-600">{{ __('Conditions bilingues') }} {{ $contractDocument['conditions_version'] }}</p>
                @endif
                @if($contract->currentVersion?->document)
                    <p class="mt-2 text-xs text-emerald-700">{{ __('Fichier privé vérifiable associé.') }}</p>
                    @can('view', $contract->currentVersion->document)
                        <a href="{{ route('documents.show', $contract->currentVersion->document) }}" class="rf-button-link mt-2">{{ __('Voir le document') }}</a>
                    @endcan
                @elseif(in_array($contract->status->value, ['draft','ready']))
                    @can('version', $contract)
                        <form method="POST" enctype="multipart/form-data" action="{{ route('contracts.version-document.store', $contract) }}" class="mt-4 space-y-3" data-loading-form>
                            @csrf
                            <x-file-input id="contract-version-file" name="file" :label="__('PDF contractuel')" required :errors="$errors->get('file')" />
                            <x-submit-button :label="__('Associer le fichier')" loading-label="{{ __('Association en cours…') }}" />
                        </form>
                    @endcan
                @endif
                @can('version', $contract)
                    <form method="POST" action="{{ route('contracts.versions.store', $contract) }}" class="mt-4 space-y-2">
                        @csrf
                        <label class="rf-field-label" for="change-reason">{{ __('Motif de la nouvelle version') }} <span class="text-red-700">*</span></label>
                        <input id="change-reason" name="change_reason" required value="{{ old('change_reason') }}" class="w-full">
                        <x-field-error :messages="$errors->get('change_reason')" />
                        <x-secondary-button type="submit">{{ __('Créer une version') }}</x-secondary-button>
                    </form>
                @endcan
            </x-section-card>
        </div>

        <section class="rounded-xl bg-white p-5 shadow-sm"><h2 class="font-semibold">{{ __('Actions du cycle') }}</h2><div class="mt-4 flex flex-wrap gap-3">
            @if($contract->status->value === 'draft')@can('version', $contract)<form method="POST" action="{{ route('contracts.ready', $contract) }}">@csrf<button class="rounded-lg bg-slate-900 px-4 py-2 text-sm text-white">{{ __('Marquer prêt') }}</button></form>@endcan @endif
            @if($contract->status->value === 'ready')@can('accept', $contract)<form method="POST" action="{{ route('contracts.accept', $contract) }}" class="flex flex-wrap gap-2">@csrf<input name="accepted_by_name" required value="{{ old('accepted_by_name') }}" aria-label="{{ __('Nom du signataire') }}" placeholder="{{ __('Nom du signataire') }}" class="text-sm"><select name="acceptance_method" aria-label="{{ __('Mode d’acceptation') }}" class="text-sm">@foreach($acceptanceMethods as $method)<option value="{{ $method->value }}">{{ App\Support\Ui\UiLabel::get($method) }}</option>@endforeach</select><x-primary-button>{{ __('Enregistrer l’acceptation') }}</x-primary-button></form>@endcan @endif
            @if(in_array($contract->status->value, ['draft','ready']))@can('cancel', $contract)<form method="POST" action="{{ route('contracts.cancel', $contract) }}" class="flex gap-2">@csrf<input name="reason" required placeholder="{{ __('Motif') }}" class="rounded-lg border-slate-300 text-sm"><button class="rounded-lg bg-red-700 px-4 py-2 text-sm text-white">{{ __('Annuler') }}</button></form>@endcan @endif
        </div></section>

        @if(auth()->user()->hasPermission('inspection.manage'))
            <section class="rf-panel rf-panel-body flex flex-wrap items-center gap-3">
                <h2 class="w-full font-semibold">{{ __('États des lieux guidés') }}</h2>
                @if($contract->status->value === 'accepted' || $departure)
                    <a class="rf-button-secondary" href="{{ route('inspections.guided.show', ['contract' => $contract, 'kind' => 'departure']) }}">{{ __('Départ : relevés et photos') }}</a>
                @endif
                @if(in_array($contract->status->value, ['active', 'return_pending', 'returned', 'closed']) || $return)
                    <a class="rf-button-secondary" href="{{ route('inspections.guided.show', ['contract' => $contract, 'kind' => 'return']) }}">{{ __('Retour : comparaison et photos') }}</a>
                @endif
            </section>
        @endif
        @if($contract->status->value === 'accepted' && !$departure && auth()->user()->hasPermission('inspection.manage'))
        <section class="rounded-xl bg-white p-5 shadow-sm"><h2 class="font-semibold">{{ __('Inspection de départ') }}</h2><form method="POST" action="{{ route('contracts.departure-inspection', $contract) }}" class="mt-4 space-y-4">@csrf
            <div class="grid gap-3 md:grid-cols-3"><div><x-input-label for="departure-mileage" :value="__('Kilométrage de départ')" required /><input id="departure-mileage" type="number" name="mileage" required min="0" value="{{ old('mileage') }}" aria-describedby="departure-mileage-error" class="mt-1 w-full rounded-lg border-slate-300"><x-field-error id="departure-mileage-error" :messages="$errors->get('mileage')" /></div><div><x-input-label for="departure-fuel" :value="__('Niveau de carburant (%)')" required /><input id="departure-fuel" type="number" name="fuel_level" required min="0" max="100" step="0.01" value="{{ old('fuel_level') }}" aria-describedby="departure-fuel-error" class="mt-1 w-full rounded-lg border-slate-300"><x-field-error id="departure-fuel-error" :messages="$errors->get('fuel_level')" /></div><div><x-input-label for="departure-notes" :value="__('Notes')" /><input id="departure-notes" name="notes" value="{{ old('notes') }}" aria-describedby="departure-notes-error" class="mt-1 w-full rounded-lg border-slate-300"><x-field-error id="departure-notes-error" :messages="$errors->get('notes')" /></div></div>
            <fieldset><legend class="mb-2 text-sm font-semibold">{{ __('État des éléments contrôlés') }}</legend><div class="grid gap-3 md:grid-cols-2">@foreach(['body'=>'Carrosserie','interior'=>'Habitacle','tyres'=>'Pneus','equipment'=>'Équipements'] as $code => $label)<div><input type="hidden" name="items[{{ $loop->index }}][item_code]" value="{{ $code }}"><input type="hidden" name="items[{{ $loop->index }}][label]" value="{{ $label }}"><x-input-label for="departure-item-{{ $code }}" :value="__($label)" required /><select id="departure-item-{{ $code }}" name="items[{{ $loop->index }}][condition]" class="mt-1 w-full rounded-lg border-slate-300"><option value="good">{{ __('Bon') }}</option><option value="damaged">{{ __('Endommagé') }}</option><option value="missing">{{ __('Manquant') }}</option><option value="not_checked">{{ __('Non contrôlé') }}</option></select></div>@endforeach</div></fieldset><button class="rounded-lg bg-slate-900 px-4 py-2 text-white">{{ __('Terminer l’inspection') }}</button>
        </form></section>
        @elseif($contract->status->value === 'accepted' && $departure)
        @can('activate', $contract)<form method="POST" action="{{ route('contracts.activate', $contract) }}">@csrf<button class="rounded-lg bg-emerald-700 px-4 py-2 text-white">{{ __('Activer le contrat et remettre le véhicule') }}</button></form>@endcan
        @endif

        @if($contract->status->value === 'active' && auth()->user()->hasPermission('inspection.manage'))
        <section
            class="rounded-xl bg-white p-5 shadow-sm"
            x-data='returnDamageAssistant(@json($damageAssistantConfiguration))'
        >
            <h2 class="font-semibold">{{ __('Inspection de retour') }}</h2>
            <form method="POST" action="{{ route('contracts.return-inspection', $contract) }}" class="mt-4 space-y-4">@csrf
            <div class="grid gap-3 md:grid-cols-3"><div><x-input-label for="return-mileage" :value="__('Kilométrage de retour')" required /><input id="return-mileage" type="number" name="mileage" required min="{{ $contract->start_mileage }}" value="{{ old('mileage') }}" aria-describedby="return-mileage-error" class="mt-1 w-full rounded-lg border-slate-300"><x-field-error id="return-mileage-error" :messages="$errors->get('mileage')" /></div><div><x-input-label for="return-fuel" :value="__('Niveau de carburant (%)')" required /><input id="return-fuel" type="number" name="fuel_level" required min="0" max="100" step="0.01" value="{{ old('fuel_level') }}" aria-describedby="return-fuel-error" class="mt-1 w-full rounded-lg border-slate-300"><x-field-error id="return-fuel-error" :messages="$errors->get('fuel_level')" /></div><div><x-input-label for="return-notes" :value="__('Observations')" /><textarea id="return-notes" name="notes" x-model="notes" rows="3" aria-describedby="return-notes-error" class="mt-1 w-full rounded-lg border-slate-300"></textarea><x-field-error id="return-notes-error" :messages="$errors->get('notes')" /></div></div>

            @if($damageAssistant['visible'])
                <div class="rounded-xl border border-blue-200 bg-blue-50/60 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-slate-950">{{ __('Aide à l’inspection visuelle') }}</h3>
                            <p class="mt-1 max-w-3xl text-sm text-slate-700">{{ __('Ajoutez des photos puis lancez chaque analyse explicitement. Une suggestion n’est ajoutée aux observations qu’après votre confirmation.') }}</p>
                        </div>
                        <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-700">{{ __('Décision humaine') }}</span>
                    </div>
                    <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">{{ __('Cette analyse est une aide visuelle. Vérifiez toujours l’ensemble du véhicule avant de valider le retour.') }}</p>

                    @if($damageAssistant['ready'])
                        <div class="mt-4">
                            <x-input-label for="return-damage-photos" :value="__('Photos du véhicule')" />
                            <div class="mt-2 rounded-2xl border-2 border-dashed border-belkhir-space-border bg-belkhir-space-canvas transition hover:border-slate-400">
                                <input
                                    id="return-damage-photos"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    multiple
                                    class="peer sr-only"
                                    x-on:change="addSelectedFiles($event.target.files); $event.target.value = ''"
                                >
                                <label for="return-damage-photos" class="flex min-h-28 cursor-pointer flex-col items-center justify-center rounded-2xl px-5 py-5 text-center peer-focus-visible:ring-2 peer-focus-visible:ring-belkhir-space-blue peer-focus-visible:ring-offset-2">
                                    <span aria-hidden="true" class="flex h-11 w-11 items-center justify-center rounded-xl bg-belkhir-space-orange-soft text-belkhir-space-orange"><x-icon name="image" size="lg" /></span>
                                    <span class="mt-3 text-sm font-semibold text-belkhir-space-blue">{{ __('Choisir des photos') }}</span>
                                    <span class="mt-1 text-xs text-belkhir-space-muted">{{ __('JPEG, PNG ou WebP · sélection multiple possible') }}</span>
                                </label>
                            </div>
                            <p class="mt-1 text-xs text-slate-600"><span x-text="photos.length"></span> {{ __('photo(s) sélectionnée(s). Chaque photo garde son propre résultat ; l’inspection manuelle reste disponible.') }}</p>
                        </div>

                        <div class="mt-4 grid gap-4 lg:grid-cols-2">
                            <template x-for="photo in photos" :key="photo.id">
                                <article class="rounded-xl border border-slate-200 bg-white p-3">
                                    <div class="flex items-start gap-3">
                                        <div class="aspect-[4/3] w-32 shrink-0 overflow-hidden rounded-xl border border-belkhir-space-border bg-slate-100">
                                            <img x-cloak x-show="photo.preview" :src="photo.preview" alt="{{ __('Photo sélectionnée pour l’inspection de retour') }}" class="h-full w-full object-contain">
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-semibold" x-text="photo.name"></p>
                                            <div class="mt-2 flex flex-wrap gap-2">
                                                <button type="button" class="rf-button-secondary" x-on:click="analyze(photo)" :disabled="photo.phase === 'uploading' || photo.phase === 'processing'" :aria-busy="(photo.phase === 'uploading' || photo.phase === 'processing').toString()">
                                                    <x-spinner x-cloak x-show="photo.phase === 'uploading' || photo.phase === 'processing'" />
                                                    <x-icon name="analysis" size="xs" x-show="photo.phase !== 'uploading' && photo.phase !== 'processing'" />
                                                    <span x-text="photo.phase === 'uploading' || photo.phase === 'processing' ? 'Analyse en cours…' : 'Analyser cette photo'">{{ __('Analyser cette photo') }}</span>
                                                </button>
                                                <x-quiet-button x-on:click="removePhoto(photo)">{{ __('Retirer') }}</x-quiet-button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mt-3 text-sm" aria-live="polite">
                                        <p x-cloak x-show="photo.message" x-text="photo.message" class="text-slate-700" role="status" aria-live="polite"></p>
                                        <ul x-cloak x-show="photo.detections.length > 0" class="mt-2 space-y-1">
                                            <template x-for="(detection, index) in photo.detections" :key="index">
                                                <li class="rounded bg-amber-50 px-2 py-1 text-amber-950"><span x-text="detection.label"></span> {{ __('— confiance indicative') }} <span x-text="confidenceText(detection.confidence)"></span></li>
                                            </template>
                                        </ul>
                                        <div x-cloak x-show="photo.detections.length > 0" class="mt-3 flex flex-wrap gap-2">
                                            <button x-cloak x-show="! photo.suggestionApplied" type="button" class="rf-button-secondary" x-on:click="addSuggestion(photo)"><x-icon name="add" size="xs" />{{ __('Ajouter aux observations') }}</button>
                                            <x-quiet-button x-cloak x-show="photo.suggestionApplied" x-on:click="removeSuggestion(photo)">{{ __('Supprimer la suggestion') }}</x-quiet-button>
                                        </div>
                                    </div>
                                </article>
                            </template>
                        </div>
                    @else
                        <p class="mt-4 rounded-lg bg-white p-3 text-sm text-slate-700">{{ __('L’analyse des photos est temporairement indisponible. Vous pouvez terminer l’inspection manuellement.') }}</p>
                    @endif
                </div>
                <template x-for="runId in selectedRunIds" :key="runId">
                    <input type="hidden" name="damage_prediction_runs[]" :value="runId">
                </template>
                <x-field-error :messages="$errors->get('damage_prediction_runs')" />
            @endif

            <fieldset><legend class="mb-2 text-sm font-semibold">{{ __('État des éléments contrôlés') }}</legend><div class="grid gap-3 md:grid-cols-2">@foreach(['body'=>'Carrosserie','interior'=>'Habitacle','tyres'=>'Pneus','equipment'=>'Équipements'] as $code => $label)<div><input type="hidden" name="items[{{ $loop->index }}][item_code]" value="{{ $code }}"><input type="hidden" name="items[{{ $loop->index }}][label]" value="{{ $label }}"><x-input-label for="return-item-{{ $code }}" :value="__($label)" required /><select id="return-item-{{ $code }}" name="items[{{ $loop->index }}][condition]" class="mt-1 w-full rounded-lg border-slate-300"><option value="good">{{ __('Bon') }}</option><option value="damaged">{{ __('Endommagé') }}</option><option value="missing">{{ __('Manquant') }}</option><option value="not_checked">{{ __('Non contrôlé') }}</option></select></div>@endforeach</div></fieldset><button class="rounded-lg bg-slate-900 px-4 py-2 text-white">{{ __('Terminer le retour') }}</button>
        </form></section>
        @endif

        @if($contract->status->value === 'return_pending')
        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl bg-white p-5 shadow-sm"><h2 class="font-semibold">{{ __('Dommages') }}</h2>@foreach($contract->damages as $damage)<div class="mt-4 border-t pt-3 text-sm"><p class="font-medium">{{ $damage->damage_number }} — {{ $damage->description }}</p><div class="mt-2 flex flex-wrap gap-2"><x-status-badge :value="$damage->severity" /><x-status-badge :value="$damage->responsibility" /><x-status-badge :value="$damage->status" /></div>@if(in_array($damage->severity->value, ['major','critical']))<p class="mt-2 rounded bg-amber-50 p-2 text-amber-900">{{ __('Mise hors service recommandée. Une confirmation humaine reste requise depuis la fiche véhicule.') }}</p>@endif @can('review', $damage)<form method="POST" action="{{ route('damages.review', $damage) }}" class="mt-2 grid gap-2">@csrf<select name="responsibility" aria-label="{{ __('Responsabilité') }}"><option value="customer">{{ __('Client') }}</option><option value="agency">{{ __('Agence') }}</option><option value="insurance">{{ __('Assurance') }}</option><option value="unknown">{{ __('Indéterminée') }}</option></select><select name="status" aria-label="{{ __('Décision') }}"><option value="resolved">{{ __('Résolu') }}</option><option value="dismissed">{{ __('Écarté') }}</option></select><input name="approved_cost" type="number" step="0.01" min="0" aria-label="{{ __('Coût approuvé') }}" placeholder="{{ __('Coût approuvé (') }}{{ $contract->currency }})"><input name="reason" required aria-label="{{ __('Justification humaine') }}" placeholder="{{ __('Justification humaine') }}"><x-primary-button>{{ __('Enregistrer la revue') }}</x-primary-button></form>@endcan</div>@endforeach
            @if(auth()->user()->hasPermission('damage.report'))@can('return', $contract)<form method="POST" action="{{ route('contracts.damages.store', $contract) }}" class="mt-5 grid gap-2">@csrf<input type="hidden" name="return_inspection_id" value="{{ $return?->id }}"><div><x-input-label for="damage-description" :value="__('Description du dommage')" required /><input id="damage-description" name="description" required placeholder="{{ __('Décrire le dommage constaté') }}" class="mt-1 w-full rounded border-slate-300"></div><div><x-input-label for="damage-vehicle-area" :value="__('Zone du véhicule')" /><input id="damage-vehicle-area" name="vehicle_area" placeholder="{{ __('Ex. aile avant droite') }}" class="mt-1 w-full rounded border-slate-300"></div><div><x-input-label for="damage-severity" :value="__('Gravité')" required /><select id="damage-severity" name="severity" class="mt-1 w-full rounded border-slate-300"><option value="minor">{{ __('Mineur') }}</option><option value="moderate">{{ __('Modéré') }}</option><option value="major">{{ __('Majeur') }}</option><option value="critical">{{ __('Critique') }}</option></select></div><div><x-input-label for="damage-estimated-cost" :value="__('Estimation indicative (').$contract->currency.')'" /><input id="damage-estimated-cost" name="estimated_cost" type="number" step="0.01" min="0" placeholder="0,00" class="mt-1 w-full rounded border-slate-300"></div><button class="rounded border px-3 py-2">{{ __('Signaler — responsabilité en attente') }}</button></form>@endcan @endif</section>
            @php($canReviewCharges = auth()->user()->hasPermission('charge.review'))
            @php($returnInspectionCompleted = $return?->status->value === 'completed')
            @php($hasProposedCharges = $contract->charges->contains(fn ($charge) => $charge->status->value === 'proposed'))
            @php($hasBlockingDamage = $contract->damages->contains(fn ($damage) => in_array($damage->status->value, ['reported', 'under_review'], true) || $damage->responsibility->value === 'pending'))
            <section class="rounded-xl bg-white p-5 shadow-sm">
                <h2 class="font-semibold">{{ __('Frais de retour') }}</h2>
                @if($canReviewCharges)
                    @can('return', $contract)
                        <form method="POST" action="{{ route('contracts.charges', $contract) }}" class="mt-3 flex flex-wrap items-end gap-2">
                            @csrf
                            <label class="pb-2 text-sm"><input type="checkbox" name="cleaning_approved" value="1"> {{ __('Nettoyage constaté et approuvé') }}</label>
                            <div class="min-w-40 flex-1"><x-input-label for="cleaning-amount" :value="__('Montant du nettoyage (').$contract->currency.')'" /><input id="cleaning-amount" name="cleaning_amount" type="number" step="0.01" min="0" placeholder="0,00" class="mt-1 w-full rounded border-slate-300"></div>
                            <button class="rounded border px-3 py-2">{{ __('Calculer les propositions') }}</button>
                        </form>
                    @endcan
                @endif

                @if($returnInspectionCompleted && ! $hasBlockingDamage && ($canReviewCharges || ! $hasProposedCharges))
                    @can('return', $contract)
                        <form method="POST" action="{{ route('contracts.returned', $contract) }}" class="mt-5 space-y-3">
                            @csrf
                            @forelse($contract->charges as $charge)
                                <div class="rounded border p-3 text-sm">
                                    <p class="font-medium">{{ $charge->description }} — {{ App\Support\Ui\UiLabel::money($charge->total_amount, $contract->currency) }}</p>
                                    @if($canReviewCharges && $charge->status->value === 'proposed')
                                        <label class="me-4"><input type="checkbox" name="approved_charge_ids[]" value="{{ $charge->id }}"> {{ __('Approuver') }}</label>
                                        <label><input type="checkbox" name="rejected_charge_ids[]" value="{{ $charge->id }}"> {{ __('Rejeter') }}</label>
                                    @else
                                        <x-status-badge :value="$charge->status" />
                                    @endif
                                </div>
                            @empty
                                <p class="text-sm text-slate-500">{{ __('Aucun frais proposé.') }}</p>
                            @endforelse
                            <div><x-input-label for="return-reason" :value="__('Note de retour')" /><input id="return-reason" name="reason" placeholder="{{ __('Ajouter une note facultative') }}" class="mt-1 w-full"></div>
                            <x-primary-button>{{ __('Finaliser le retour') }}</x-primary-button>
                        </form>
                    @endcan
                @elseif($hasBlockingDamage)
                    <p class="mt-4 text-sm text-amber-800">{{ __('La finalisation attend la revue humaine des dommages signalés.') }}</p>
                @elseif($hasProposedCharges && ! $canReviewCharges)
                    <p class="mt-4 text-sm text-amber-800">{{ __('La finalisation attend la décision d’une personne autorisée sur les frais proposés.') }}</p>
                @endif
            </section>
        </div>
        @endif

        @if($comparison)<section class="rounded-xl bg-white p-5 shadow-sm"><h2 class="font-semibold">{{ __('Comparaison départ / retour') }}</h2><div class="mt-3 grid gap-3 text-sm md:grid-cols-3"><p>{{ __('Kilométrage :') }} <strong>+{{ $comparison['mileage_delta'] }} {{ __('km') }}</strong></p><p>{{ __('Carburant :') }} <strong>{{ $comparison['fuel_delta'] }} {{ __('point(s)') }}</strong></p><p>{{ __('Anomalies à revoir :') }} <strong>{{ count($comparison['damage_candidates']) }}</strong></p></div><ul class="mt-3 text-sm">@foreach($comparison['items'] as $item)<li>{{ $item['label'] }} : {{ App\Support\Ui\UiLabel::get($item['before']) }} → {{ App\Support\Ui\UiLabel::get($item['after']) }}</li>@endforeach</ul></section>@endif
        @if($contract->inspections->isNotEmpty() || $contract->damages->isNotEmpty())
            <x-section-card :title="__('Photos privées')" :description="__('Les fichiers passent par le stockage privé existant ; aucune URL publique n’est créée.')">
                <div class="grid gap-4 lg:grid-cols-2">
                    @foreach($contract->inspections as $inspection)
                        @can('manage', $inspection)
                            <form method="POST" enctype="multipart/form-data" action="{{ route('inspections.documents.store', $inspection) }}" class="rounded-2xl border border-belkhir-space-border p-4" data-loading-form>
                                @csrf
                                <input type="hidden" name="document_type" value="inspection_photo">
                                <input type="hidden" name="title" value="Photo inspection {{ App\Support\Ui\UiLabel::get($inspection->inspection_type) }}">
                                <input type="hidden" name="is_sensitive" value="1">
                                <x-file-input :id="'inspection-photo-'.$inspection->id" name="file" :label="__('Photo inspection ').App\Support\Ui\UiLabel::get($inspection->inspection_type)" preview="image" fit="contain" required :errors="$errors->get('file')" />
                                <x-submit-button class="mt-3" :label="__('Ajouter en privé')" loading-label="{{ __('Ajout en cours…') }}" />
                            </form>
                        @endcan
                    @endforeach
                    @foreach($contract->damages as $damage)
                        @can('report', $damage)
                            <form method="POST" enctype="multipart/form-data" action="{{ route('damages.documents.store', $damage) }}" class="rounded-2xl border border-belkhir-space-border p-4" data-loading-form>
                                @csrf
                                <input type="hidden" name="document_type" value="damage_photo">
                                <input type="hidden" name="title" value="Photo dommage {{ $damage->damage_number }}">
                                <input type="hidden" name="is_sensitive" value="1">
                                <x-file-input :id="'damage-photo-'.$damage->id" name="file" :label="__('Photo ').$damage->damage_number" preview="image" fit="contain" required :errors="$errors->get('file')" />
                                <x-submit-button class="mt-3" :label="__('Ajouter en privé')" loading-label="{{ __('Ajout en cours…') }}" />
                            </form>
                        @endcan
                    @endforeach
                </div>
            </x-section-card>
        @endif
        @include('contracts.partials.finance')
        <x-section-card :title="__('Historique du contrat')"><x-timeline :label="__('Cycle du contrat')">@foreach($contract->statusHistories->sortByDesc('created_at') as $history)<x-timeline-item :title="($history->from_status?->label() ?? __('Création')).' → '.$history->to_status->label()" :meta="App\Support\Ui\UiLabel::dateTime($history->created_at)" :active="$loop->first"></x-timeline-item>@endforeach</x-timeline></x-section-card>
    </div>
</x-app-layout>
