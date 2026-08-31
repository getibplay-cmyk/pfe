@props([
    'title' => 'Filtres',
    'activeCount' => 0,
    'resultCount' => null,
    'collapsible' => true,
])
@php
    $filterBodyId = ($attributes->get('id') ?: 'filtres-'.Illuminate\Support\Str::ulid()).'-contenu';
@endphp
<section
    aria-label="{{ $title }}"
    @if ($collapsible) x-data="{ filtersOpen: false }" @endif
    {{ $attributes->class('rf-filter-panel') }}
>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3">
            <span aria-hidden="true" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-belkhir-space-blue">
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-5 w-5" focusable="false">
                    <path d="M4 5h16M7 12h10m-6 7h2" stroke-width="1.8" stroke-linecap="round" />
                </svg>
            </span>
            <div class="min-w-0">
                <h2 class="truncate text-sm font-bold text-belkhir-space-text">{{ $title }}</h2>
                <p class="mt-0.5 text-xs text-belkhir-space-muted">
                    @if ((int) $activeCount > 0)
                        {{ App\Support\Ui\BusinessNumber::count($activeCount, 'filtre') }} actif{{ (int) $activeCount > 1 ? 's' : '' }}
                    @else
                        Affinez les résultats sans quitter la page.
                    @endif
                    @if ($resultCount !== null) · {{ App\Support\Ui\BusinessNumber::count($resultCount, 'résultat') }} @endif
                </p>
            </div>
        </div>

        <div class="flex items-center gap-2">
            @if (isset($aside))<div class="hidden text-xs text-belkhir-space-muted sm:block">{{ $aside }}</div>@endif
            @if ($collapsible)
                <button
                    type="button"
                    class="rf-button-secondary min-h-10 px-3 md:hidden"
                    x-on:click="filtersOpen = ! filtersOpen"
                    x-bind:aria-expanded="filtersOpen.toString()"
                    aria-controls="{{ $filterBodyId }}"
                >
                    <span x-text="filtersOpen ? 'Masquer' : 'Filtres'">Filtres</span>
                    @if ((int) $activeCount > 0)<span class="rounded-md bg-belkhir-space-blue px-1.5 py-0.5 text-xs text-white">{{ $activeCount }}</span>@endif
                </button>
            @endif
        </div>
    </div>

    @if (isset($tags))
        <div class="mt-3 flex flex-wrap gap-2" aria-label="Filtres actifs">{{ $tags }}</div>
    @endif

    <div
        id="{{ $filterBodyId }}"
        @if ($collapsible) x-bind:class="filtersOpen ? 'block' : 'hidden md:block'" @endif
        @class(['mt-4', 'hidden md:block' => $collapsible])
    >
        {{ $slot }}
    </div>
</section>
