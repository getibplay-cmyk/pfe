<x-app-layout>
    <div class="rf-page max-w-5xl">
        <x-page-header :title="__('Recherche et favoris')" :description="__('Retrouvez vos véhicules, clients, réservations et contrats autorisés. Raccourci : Ctrl ou ⌘ + K.')" />
        <form method="GET" class="flex gap-3"><label for="workspace-query" class="sr-only">{{ __('Rechercher') }}</label><x-text-input id="workspace-query" name="q" :value="$term" maxlength="80" :placeholder="__('Au moins deux caractères')" /><x-primary-button icon="search">{{ __('Rechercher') }}</x-primary-button></form>
        @if ($term !== '')
            <x-section-card :title="__('Résultats')"><ul class="divide-y divide-slate-100">
                @forelse ($results as $result)<li class="flex flex-wrap items-center justify-between gap-3 py-3"><a href="{{ $result['url'] }}" class="font-semibold text-brand-700"><span class="block text-xs font-normal text-slate-500">{{ $result['group'] }}</span>{{ $result['label'] }}</a><form method="POST" action="{{ route('workspace.favorite') }}">@csrf<input type="hidden" name="kind" value="{{ $result['kind'] }}"><input type="hidden" name="id" value="{{ $result['id'] }}"><button class="rf-button-secondary">{{ __('Ajouter aux favoris') }}</button></form></li>
                @empty<li class="py-3 text-sm">{{ mb_strlen($term) < 2 ? __('Au moins deux caractères') : __('Aucun résultat dans votre périmètre.') }}</li>@endforelse
            </ul></x-section-card>
        @endif
        <div class="grid gap-6 md:grid-cols-2">
            <x-section-card :title="__('Mes favoris')"><ul class="divide-y divide-slate-100">
                @forelse ($favorites as $favorite)<li class="flex items-center justify-between gap-3 py-3"><a class="text-brand-700" href="{{ $favorite['url'] }}">{{ $favorite['label'] }}</a><form method="POST" action="{{ route('workspace.unfavorite') }}">@csrf @method('DELETE')<input type="hidden" name="kind" value="{{ $favorite['kind'] }}"><input type="hidden" name="id" value="{{ $favorite['id'] }}"><button class="rf-button-link">{{ __('Retirer') }}</button></form></li>
                @empty<li class="text-sm text-slate-600">{{ __('Ajoutez un résultat de recherche pour le retrouver ici.') }}</li>@endforelse
            </ul></x-section-card>
            <x-section-card :title="__('Mes filtres')"><ul class="divide-y divide-slate-100">
                @forelse ($filters as $filter)<li class="flex items-center justify-between gap-3 py-3"><a class="text-brand-700" href="{{ $filter['url'] }}"><span class="block text-xs text-slate-500">{{ $filter['group'] }}</span>{{ $filter['label'] }}</a><form method="POST" action="{{ route('workspace.filters.destroy') }}">@csrf @method('DELETE')<input type="hidden" name="id" value="{{ $filter['id'] }}"><button class="rf-button-link">{{ __('Retirer') }}</button></form></li>
                @empty<li class="text-sm text-slate-600">{{ __('Enregistrez les filtres depuis une liste ou le planning.') }}</li>@endforelse
            </ul></x-section-card>
        </div>
    </div>
</x-app-layout>
