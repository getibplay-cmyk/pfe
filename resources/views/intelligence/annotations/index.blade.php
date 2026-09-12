<x-app-layout>
    <div class="rf-page">
        <x-page-header :title="__('Annotations et jeux d’apprentissage')" :description="__('Validez les corrections depuis les analyses de couleur, plaque ou dommages, puis préparez un jeu privé accompagné de ses images.')" />
        <div class="flex flex-wrap gap-3">
            @foreach(['color' => 'Couleurs', 'plate' => 'Plaques', 'damage' => 'Dommages'] as $key => $label)<a class="rf-button-secondary" href="{{ route('annotations.index', ['family' => $key]) }}" @if($key === $family) aria-current="page" @endif>{{ __($label) }}</a>@endforeach
            <a class="rf-button-link" href="{{ route('model-quality.index') }}">{{ __('Qualité des modèles') }}</a>
        </div>
        <x-input-error :messages="$errors->all()" />
        <form method="POST" action="{{ route('annotations.export') }}" class="space-y-5">
            @csrf<input type="hidden" name="family" value="{{ $family }}">
            <div class="rf-panel rf-table-scroll"><table><thead><tr><th>{{ __('Sélection') }}</th><th>{{ __('Validation') }}</th><th>{{ __('Résultat vérifié') }}</th><th>{{ __('Comparaison') }}</th><th>{{ __('Action') }}</th></tr></thead><tbody>
                @forelse($annotations as $annotation)
                    @php($run = $runs[$annotation->$column] ?? null)
                    <tr><td>@can('create', App\Models\ModelTrainingDataset::class)<input type="checkbox" name="annotations[]" value="{{ $annotation->id }}" class="rounded border-slate-300" aria-label="{{ __('Sélectionner cette annotation') }}">@endcan</td><td>{{ App\Support\Ui\UiLabel::dateTime($annotation->created_at) }}<span class="block text-xs text-slate-500">{{ __('Version') }} {{ $annotation->revision }}</span></td><td>{{ $family === 'damage' ? count($annotation->truth['boxes']).' '.__('cadres') : $annotation->truth['label'] }}</td><td>{{ $annotation->matches_prediction === null ? __('Non comparable') : ($annotation->matches_prediction ? __('Suggestion confirmée') : __('Suggestion corrigée')) }}</td><td>@if($run)<a class="rf-button-link" href="{{ route('annotations.edit', ['family' => $family, 'run' => $run->run_id]) }}">{{ __('Vérifier') }}</a>@endif</td></tr>
                @empty<tr><td colspan="5">{{ __('Aucune annotation validée. Ouvrez une analyse terminée pour commencer.') }}</td></tr>@endforelse
            </tbody></table></div>
            @can('create', App\Models\ModelTrainingDataset::class)
                <x-section-card :title="__('Préparer les images sélectionnées')">
                    <p class="mb-4 text-sm">{{ __('Chaque export contient jusqu’à 50 images, dans une limite de 64 Mo. Les photos du même véhicule restent dans le même groupe pour éviter les mélanges entre apprentissage et test.') }}</p>
                    <x-input-label for="annotation-dataset-name" :value="__('Nom du jeu')" /><x-text-input id="annotation-dataset-name" name="name" maxlength="100" required />
                    <label class="mt-4 flex items-start gap-3 text-sm"><input type="checkbox" name="rights_confirmed" value="1" required class="mt-1 rounded border-slate-300"><span>{{ __('Je confirme disposer des droits nécessaires pour utiliser ces images et annotations.') }}</span></label>
                    <label class="my-4 flex items-start gap-3 text-sm"><input type="checkbox" name="labels_confirmed" value="1" required class="mt-1 rounded border-slate-300"><span>{{ __('Je confirme avoir vérifié les annotations sélectionnées.') }}</span></label>
                    <x-primary-button>{{ __('Préparer le jeu privé') }}</x-primary-button>
                </x-section-card>
            @endcan
        </form>
        {{ $annotations->links() }}
        @if($bundles->isNotEmpty())<x-section-card :title="__('Exports préparés')"><ul class="divide-y divide-slate-100">@foreach($bundles as $bundle)<li class="flex items-center justify-between gap-3 py-3"><span>{{ $bundle->name }}</span>@if($bundle->revoked_at)<span class="text-sm text-amber-700">{{ __('Révoqué') }}</span>@else<a class="rf-button-secondary" href="{{ route('annotations.download', $bundle->public_id) }}">{{ __('Télécharger les images et annotations') }}</a>@endif</li>@endforeach</ul><a href="{{ route('model-training.index') }}" class="rf-button-link mt-3">{{ __('Gérer le partage et le réentraînement') }}</a></x-section-card>@endif
    </div>
</x-app-layout>
