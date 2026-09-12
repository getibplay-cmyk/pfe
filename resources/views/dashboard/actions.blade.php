<x-app-layout><div class="rf-page">
    <x-page-header :title="$group['title']" :description="$group['count'].__(' dossiers à traiter dans votre périmètre.')" />
    <a class="rf-button-secondary w-fit" href="{{ route('dashboard') }}">{{ __('← Tableau de bord') }}</a>
    <x-section-card :title="__('Dossiers prioritaires')">
        <ul class="divide-y">@forelse($group['items'] as $item)
            <li class="flex flex-wrap items-center justify-between gap-4 py-4"><div><h2 class="font-semibold">{{ $item['label'] }}</h2><p class="mt-1 text-sm text-slate-600">{{ $item['detail'] }}</p></div><a class="rf-button-primary" href="{{ $item['url'] }}">{{ $item['action'] }}</a></li>
        @empty<li class="py-4"><x-empty-state :title="__('Tout est à jour')" :description="__('Aucun dossier ne demande votre intervention dans cette catégorie.')" /></li>@endforelse</ul>
        {{ $group['items']->links() }}
    </x-section-card>
</div></x-app-layout>
