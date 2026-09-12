<x-app-layout>
    <div class="rf-page">
        <x-page-header :title="__('Données d’apprentissage')" :eyebrow="__('Amélioration des modèles')" :description="__('Préparez des observations vérifiées pour les prochaines sessions d’entraînement.')" />
        <x-form-errors />
        <x-section-card :title="__('Du SaaS au modèle candidat')" :description="__('Vos données restent privées. Le partage avec l’administration est un choix explicite pour chaque jeu.')">
            <ol class="grid gap-3 text-sm md:grid-cols-3"><li><strong>{{ __('1. Préparer') }}</strong><p>{{ __('Exporter les départs réels ou importer des données supplémentaires annotées.') }}</p></li><li><strong>{{ __('2. Entraîner') }}</strong><p>{{ __('L’administration regroupe les contributions autorisées et lance le notebook dans Colab avec un Drive privé.') }}</p></li><li><strong>{{ __('3. Comparer') }}</strong><p>{{ __('Une nouvelle version est examinée sur un jeu de test séparé avant sa qualification technique.') }}</p></li></ol>
        </x-section-card>
        <div class="grid items-start gap-6 xl:grid-cols-2">
            @foreach(['demand' => 'Historique de demande du SaaS', 'upload' => 'Données supplémentaires'] as $mode => $title)
                <x-section-card :title="$title">
                    <form method="POST" action="{{ route('model-training.store') }}" enctype="multipart/form-data" class="space-y-4" data-loading-form>
                        @csrf <input type="hidden" name="mode" value="{{ $mode }}">
                        <div><x-input-label :for="$mode.'-name'" :value="__('Nom du jeu')" /><input id="{{ $mode }}-name" name="name" required maxlength="100" class="mt-1 w-full" placeholder="{{ __('Exemple : départs observés, premier semestre') }}"></div>
                        @if($mode === 'demand')
                            <div><x-input-label for="demand-agency" :value="__('Agence')" /><select id="demand-agency" name="agency_id" required class="mt-1 w-full">@foreach($agencies as $agency)<option value="{{ $agency->id }}">{{ $agency->name }}</option>@endforeach</select></div>
                            <div class="grid gap-3 sm:grid-cols-2"><div><x-input-label for="demand-from" :value="__('Du')" /><input id="demand-from" name="date_from" type="date" required class="mt-1 w-full"></div><div><x-input-label for="demand-to" :value="__('Au')" /><input id="demand-to" name="date_to" type="date" required class="mt-1 w-full" max="{{ now()->subDay()->toDateString() }}"></div></div>
                            <p class="text-sm text-slate-600">{{ __('Choisissez au moins 120 jours consécutifs terminés pour entraîner. Les jours sans départ sont inclus ; aucun nom de client n’est exporté.') }}</p>
                        @else
                            <div><x-input-label for="upload-dataset" :value="__('Jeu annoté au format JSON (5 Mo maximum)')" /><input id="upload-dataset" name="dataset" type="file" accept=".json,application/json" required class="mt-1 w-full"></div>
                            <p class="text-sm text-slate-600">{{ __('Pour les images, importez l’index annoté. Les photos et recadrages restent dans votre Drive privé ; le notebook les vérifie avant l’entraînement.') }}</p>
                            <div class="flex flex-wrap gap-3 text-sm">@foreach($catalog as $family => $item)<a class="text-brand-700 underline" href="{{ route('model-training.template', $family) }}">{{ __('Format :') }} {{ __($item['label']) }}</a>@endforeach</div>
                        @endif
                        <div><x-input-label :for="$mode.'-source'" :value="__('Origine et vérification des données')" /><textarea id="{{ $mode }}-source" name="source_note" required maxlength="300" rows="2" class="mt-1 w-full" placeholder="{{ __('Source autorisée et méthode de vérification, sans identité personnelle') }}"></textarea></div>
                        <label class="flex gap-2 text-sm"><input type="checkbox" name="rights_confirmed" value="1" required><span>{{ __('Je dispose des droits nécessaires pour utiliser ces données pour l’apprentissage.') }}</span></label>
                        <label class="flex gap-2 text-sm"><input type="checkbox" name="labels_confirmed" value="1" required><span>{{ __('Les observations ou annotations sont vérifiées ; elles ne sont pas simplement des prédictions du modèle.') }}</span></label>
                        <label class="flex gap-2 rounded-lg bg-blue-50 p-3 text-sm"><input type="checkbox" name="shared" value="1"><span>{{ __('J’autorise l’administration à regrouper ce jeu avec d’autres contributions pour l’entraîner dans Colab et le conserver dans un Drive privé.') }}</span></label>
                        <x-submit-button :label="__('Préparer le jeu')" loading-label="{{ __('Préparation…') }}" />
                    </form>
                </x-section-card>
            @endforeach
        </div>
        <x-section-card :title="__('Vos jeux de données')" :description="__('Une correction crée un nouveau jeu. Une révocation bloque les futurs usages dans le SaaS ; les copies déjà téléchargées doivent aussi être supprimées de Drive.')">
            <x-responsive-table :label="__('Jeux de données d’apprentissage')"><table><thead><tr><th>{{ __('Nom') }}</th><th>{{ __('Modèle') }}</th><th>{{ __('Observations') }}</th><th>{{ __('Partage') }}</th><th>{{ __('Actions') }}</th></tr></thead><tbody>
                @forelse($datasets as $dataset)<tr><td>{{ $dataset->name }}</td><td>{{ __($catalog[$dataset->family]['label']) }}</td><td>{{ $dataset->row_count }}</td><td>{{ $dataset->revoked_at ? __('Révoqué') : ($dataset->shared ? __('Autorisé pour les campagnes') : __('Privé')) }}</td><td>@unless($dataset->revoked_at)<div class="flex flex-wrap gap-3"><a class="rf-button-secondary" href="{{ route('model-training.download', $dataset) }}">{{ __('Télécharger') }}</a><form method="POST" action="{{ route('model-training.revoke', $dataset) }}" data-loading-form>@csrf <x-submit-button variant="secondary" :label="__('Révoquer')" loading-label="{{ __('Révocation…') }}" /></form></div>@unless($dataset->shared)<form method="POST" action="{{ route('model-training.share', $dataset) }}" class="mt-3 space-y-2" data-loading-form>@csrf <label class="flex gap-2 text-sm"><input type="checkbox" name="share_confirmed" value="1" required><span>{{ __('Autoriser les campagnes regroupées dans Colab et Drive privé.') }}</span></label><x-submit-button variant="secondary" :label="__('Autoriser le partage')" loading-label="{{ __('Autorisation…') }}" /></form>@endunless@endunless</td></tr>
                @empty<tr><td colspan="5"><x-empty-state :title="__('Aucun jeu préparé')" :description="__('Commencez par un historique de demande ou importez vos annotations vérifiées.')" /></td></tr>@endforelse
            </tbody></table><x-slot:footer>{{ $datasets->links() }}</x-slot:footer></x-responsive-table>
        </x-section-card>
    </div>
</x-app-layout>
