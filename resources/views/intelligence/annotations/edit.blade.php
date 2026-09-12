<x-app-layout>
    @php($parameters = ['family' => $family, 'run' => $prediction->run_id])
    <div class="rf-page max-w-5xl">
        <x-page-header :title="__('Vérifier et corriger une analyse')" :description="__(App\Support\Intelligence\Training\TrainingCatalog::get($family)['label'])" />
        <a class="rf-button-link" href="{{ route('annotations.index', ['family' => $family]) }}">{{ __('Annotations et jeux d’apprentissage') }}</a>
        <x-input-error :messages="$errors->all()" />
        <form method="POST" action="{{ route('annotations.store', $parameters) }}" class="space-y-6" @if($family === 'damage') data-annotation-editor data-box-label="{{ __('Cadre') }}" data-remove-label="{{ __('Supprimer') }}" @endif>
            @csrf<input type="hidden" name="previous_revision" value="{{ $latest?->revision ?? 0 }}">
            <x-section-card :title="__('Photo source')">
                <div class="relative mx-auto max-w-3xl overflow-hidden rounded-xl bg-slate-950" dir="ltr" @if($family === 'damage') data-annotation-frame @endif>
                    <img src="{{ route('annotations.image', $parameters) }}" width="{{ $image['width'] }}" height="{{ $image['height'] }}" class="block h-auto w-full" alt="{{ __('Photo source à vérifier') }}" draggable="false">
                    @if($family === 'damage')
                        <svg data-annotation-overlay viewBox="0 0 1000 1000" preserveAspectRatio="none" class="absolute inset-0 h-full w-full touch-none" aria-label="{{ __('Cadres de dommages') }}">
                            @foreach($boxes as $box)<rect x="{{ $box['x'] * 1000 }}" y="{{ $box['y'] * 1000 }}" width="{{ $box['w'] * 1000 }}" height="{{ $box['h'] * 1000 }}" fill="rgba(249,115,22,.18)" stroke="#f97316" stroke-width="3" vector-effect="non-scaling-stroke" />@endforeach
                        </svg>
                    @endif
                </div>
                @if($family === 'plate')<p class="mt-3 text-sm text-slate-600">{{ __('Vérifiez chaque chiffre et la lettre arabe sur le recadrage.') }}</p>@endif
            </x-section-card>
            <x-section-card :title="__('Correction humaine')">
                @if($family === 'damage')
                    <p class="mb-4 text-sm">{{ __('Tracez les dommages avec le doigt ou la souris. Utilisez les coordonnées en pourcentage pour ajuster les cadres avec précision. Supprimez les cadres incorrects et ajoutez les dommages oubliés.') }}</p>
                    <input type="hidden" name="boxes_json" data-annotation-boxes value="{{ old('boxes_json', json_encode($boxes)) }}">
                    <div data-annotation-list class="space-y-3"></div>
                    @can('review', $prediction)<button type="button" data-annotation-add class="rf-button-secondary mt-3">{{ __('Ajouter un cadre') }}</button>@endcan
                    <noscript><p class="mt-3 text-sm">{{ __('Activez JavaScript pour modifier les cadres. Les cadres affichés peuvent être validés après vérification complète.') }}</p></noscript>
                    <label class="mt-5 flex items-start gap-3 text-sm"><input type="checkbox" name="complete_image_review" value="1" required class="mt-1 rounded border-slate-300"><span>{{ __('J’ai vérifié toute l’image. Les cadres décrivent tous les dommages visibles ; une liste vide signifie que j’ai confirmé l’absence de dommage visible.') }}</span></label>
                @elseif($family === 'color')
                    <x-input-label for="verified-label" :value="__('Couleur vérifiée')" />
                    <select name="label" id="verified-label" required class="mt-2 w-full rounded-lg border-slate-300">
                        <option value="">{{ __('Choisir après vérification') }}</option>
                        @foreach(App\Support\Intelligence\VehicleColor\VehicleColorContract::CLASSES as $color)<option value="{{ $color }}" @selected(old('label', $label) === $color)>{{ $color === '__reject__' ? __('Photo hors périmètre ou non exploitable') : __(App\Support\Intelligence\VehicleColor\VehicleColorContract::label($color)) }}</option>@endforeach
                    </select>
                @else
                    <x-input-label for="verified-label" :value="__('Immatriculation vérifiée')" />
                    <x-text-input id="verified-label" name="label" dir="ltr" maxlength="30" required :value="old('label', $label)" placeholder="12345|أ|6" />
                    <p class="mt-2 text-sm text-slate-600">{{ __('Format : numéro, barre verticale, lettre arabe, barre verticale, région.') }}</p>
                @endif
                @can('review', $prediction)
                    <label class="my-5 flex items-start gap-3 text-sm"><input type="checkbox" name="validated" value="1" required class="mt-1 rounded border-slate-300"><span>{{ __('Je valide cette annotation pour la préparation de données d’apprentissage. Elle ne modifie ni le véhicule, ni le contrat, ni la responsabilité du locataire.') }}</span></label>
                    <x-primary-button>{{ __('Valider cette version') }}</x-primary-button>
                @endcan
            </x-section-card>
        </form>
        <x-section-card :title="__('Historique des validations')"><ul class="divide-y divide-slate-100">
            @forelse($history as $annotation)<li class="py-3 text-sm">{{ __('Version') }} {{ $annotation->revision }} · {{ App\Support\Ui\UiLabel::dateTime($annotation->created_at) }} · {{ $family === 'damage' ? count($annotation->truth['boxes']).' '.__('cadres') : $annotation->truth['label'] }}</li>@empty<li class="text-sm text-slate-500">{{ __('Aucune annotation validée pour cette analyse.') }}</li>@endforelse
        </ul></x-section-card>
    </div>
</x-app-layout>
