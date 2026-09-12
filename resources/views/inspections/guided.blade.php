<x-app-layout>
    @php
        $data = $draft?->data ?? ($inspection ? ['mileage' => $inspection->mileage, 'fuel_level' => $inspection->fuel_level, 'notes' => $inspection->notes, 'conditions' => $inspection->items->mapWithKeys(fn ($item) => [$item->item_code => $item->condition->value])->all()] : []);
        $done = $draft?->completed_at !== null || $inspection !== null;
        $parameters = ['contract' => $contract, 'kind' => $kind];
    @endphp
    <div class="rf-page max-w-4xl" data-inspection-guide data-saved="{{ __('Brouillon enregistré') }}" data-saving="{{ __('Enregistrement en cours…') }}" data-offline="{{ __('Connexion interrompue. Vos dernières données enregistrées pourront être reprises ici.') }}" data-conflict="{{ __('Ce brouillon a changé sur un autre appareil. Rechargez la page pour reprendre la dernière version.') }}" data-failed="{{ __('Enregistrement impossible. Vérifiez les champs puis réessayez.') }}">
        <x-page-header :title="($kind === 'departure' ? __('État des lieux de départ') : __('État des lieux de retour'))" :description="$contract->contract_number.' · '.$contract->vehicle->registration_number" />
        <a href="{{ route('contracts.show', $contract) }}" class="rf-button-link">{{ __('Retour au contrat') }}</a>
        @if ($done)
            <p class="rounded-lg bg-emerald-50 p-4 font-semibold text-emerald-800">{{ __('État des lieux terminé. Les observations et les photos sélectionnées sont conservées.') }}</p>
        @else
            <p class="rounded-lg bg-brand-50 p-4 text-sm">{{ __('Commencez par les relevés, puis prenez les six vues. Vous pouvez enregistrer et reprendre ce brouillon sur un autre appareil.') }}</p>
            <p data-guide-status role="status" class="text-sm text-slate-600">{{ $draft ? __('Brouillon repris à la dernière sauvegarde.') : __('Aucun brouillon enregistré pour le moment.') }}</p>
            <div data-guide-errors role="alert" class="text-sm text-red-700"><x-input-error :messages="$errors->all()" /></div>
        @endif
        <x-section-card :title="__('1. Relevés et observations')">
            <form id="guided-inspection-data" data-guide-form method="POST" action="{{ route('inspections.guided.save', $parameters) }}" class="space-y-4">
                @csrf
                <input type="hidden" name="revision" value="{{ $draft?->revision ?? 0 }}">
                <fieldset @disabled($done) class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><x-input-label for="guided-mileage" :value="__('Kilométrage')" /><x-text-input id="guided-mileage" type="number" name="mileage" min="0" max="10000000" inputmode="numeric" :value="old('mileage', $data['mileage'] ?? $contract->vehicle->current_mileage)" /></div>
                        <div><x-input-label for="guided-fuel" :value="__('Carburant (%)')" /><x-text-input id="guided-fuel" type="number" name="fuel_level" min="0" max="100" step="0.01" inputmode="decimal" :value="old('fuel_level', $data['fuel_level'] ?? '')" /></div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach (App\Support\Rentals\GuidedInspection::ITEMS as $code => $label)
                            <div><x-input-label :for="'guided-'.$code" :value="__($label)" /><select id="guided-{{ $code }}" name="conditions[{{ $code }}]" class="w-full rounded-lg border-slate-300">
                                @foreach (['not_checked' => 'Non vérifié', 'good' => 'Bon état', 'damaged' => 'Endommagé', 'missing' => 'Manquant'] as $value => $text)<option value="{{ $value }}" @selected(old('conditions.'.$code, $data['conditions'][$code] ?? 'not_checked') === $value)>{{ __($text) }}</option>@endforeach
                            </select></div>
                        @endforeach
                    </div>
                    <div><x-input-label for="guided-notes" :value="__('Observations')" /><textarea id="guided-notes" name="notes" rows="3" maxlength="5000" class="w-full rounded-lg border-slate-300">{{ old('notes', $data['notes'] ?? '') }}</textarea></div>
                    <div><x-input-label for="guided-omission" :value="__('Explication des photos manquantes, si nécessaire')" /><textarea id="guided-omission" name="photo_omission_reason" rows="2" maxlength="1000" class="w-full rounded-lg border-slate-300">{{ old('photo_omission_reason', $data['photo_omission_reason'] ?? '') }}</textarea></div>
                    <x-primary-button>{{ __('Enregistrer le brouillon') }}</x-primary-button>
                </fieldset>
            </form>
        </x-section-card>
        <x-section-card :title="__('2. Photos guidées')" :description="__('Cadrez chaque vue dans une lumière suffisante. Les anciennes prises sont conservées ; la dernière vue de chaque angle sera sélectionnée.')">
            <noscript><p class="mb-4 text-sm">{{ __('Enregistrez les relevés avant d’ajouter une photo pour conserver votre saisie.') }}</p></noscript>
            <div class="grid gap-5 sm:grid-cols-2">
                @foreach (App\Support\Rentals\GuidedInspection::ANGLES as $angle => $label)
                    <article class="rounded-xl border border-slate-200 p-3" data-photo-angle="{{ $angle }}">
                        <h3 class="mb-3 font-semibold">{{ __($label) }}</h3>
                        @if ($kind === 'return' && isset($before[$angle]) && auth()->user()->hasPermission('document.view'))
                            <figure class="mb-3"><img class="aspect-video w-full rounded-lg object-contain bg-slate-100" loading="lazy" src="{{ route('inspections.guided.image', ['contract' => $contract, 'kind' => 'departure', 'photo' => $before[$angle]->id]) }}" alt="{{ __('Photo de départ') }} — {{ __($label) }}"><figcaption class="text-xs text-slate-600">{{ __('Départ') }}</figcaption></figure>
                        @endif
                        @if (auth()->user()->hasPermission('document.view'))
                            <img data-guide-photo @if(isset($photos[$angle])) src="{{ route('inspections.guided.image', [...$parameters, 'photo' => $photos[$angle]->id]) }}" @else hidden @endif class="mb-3 aspect-video w-full rounded-lg bg-slate-100 object-contain" alt="{{ __($label) }}" loading="lazy">
                        @endif
                        <p data-guide-photo-status class="mb-2 text-xs text-slate-600">{{ isset($photos[$angle]) ? __('Photo enregistrée') : __('Photo à ajouter') }}</p>
                        @if (! $done && auth()->user()->hasPermission('document.upload'))
                            <form data-guide-photo-form method="POST" enctype="multipart/form-data" action="{{ route('inspections.guided.photo', $parameters) }}" class="space-y-3">
                                @csrf<input type="hidden" name="revision" value="{{ $draft?->revision ?? 0 }}"><input type="hidden" name="angle" value="{{ $angle }}">
                                <label for="photo-{{ $angle }}" class="sr-only">{{ __('Prendre ou choisir une photo') }} — {{ __($label) }}</label>
                                <input id="photo-{{ $angle }}" type="file" name="file" accept="image/jpeg,image/png,image/webp" capture="environment" required class="block w-full text-sm">
                                <button class="rf-button-secondary">{{ __('Enregistrer la photo') }}</button>
                            </form>
                        @endif
                    </article>
                @endforeach
            </div>
        </x-section-card>
        @unless ($done)
            <x-section-card :title="__('3. Vérification finale')">
                <p class="mb-4 text-sm">{{ __('Vérifiez les relevés et les photos avant de terminer. Toute anomalie devra être examinée par l’agence.') }}</p>
                <label class="mb-4 flex items-start gap-3 text-sm"><input form="guided-inspection-data" type="checkbox" name="confirmed" value="1" class="mt-1 rounded border-slate-300"><span>{{ __('Je confirme avoir vérifié cet état des lieux.') }}</span></label>
                <button form="guided-inspection-data" data-guide-complete formaction="{{ route('inspections.guided.complete', $parameters) }}" class="rf-button-primary">{{ __('Terminer l’état des lieux') }}</button>
            </x-section-card>
        @endunless
    </div>
</x-app-layout>
