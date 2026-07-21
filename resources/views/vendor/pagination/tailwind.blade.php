@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-slate-600" aria-live="polite">
            Affichage de <span class="font-semibold">{{ $paginator->firstItem() }}</span>
            à <span class="font-semibold">{{ $paginator->lastItem() }}</span>
            sur <span class="font-semibold">{{ $paginator->total() }}</span> résultat(s)
        </p>

        <div class="flex flex-wrap items-center gap-1">
            @if ($paginator->onFirstPage())
                <span aria-disabled="true" class="rf-button-secondary cursor-not-allowed opacity-50">Précédent</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="rf-button-secondary">Précédent</a>
            @endif

            <span class="hidden items-center sm:flex">
                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span aria-disabled="true" class="px-3 py-2 text-sm text-slate-500">{{ $element }}</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span aria-current="page" class="mx-0.5 inline-flex min-h-10 min-w-10 items-center justify-center rounded-lg bg-brand-700 px-3 py-2 text-sm font-semibold text-white">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" class="mx-0.5 inline-flex min-h-10 min-w-10 items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" aria-label="Aller à la page {{ $page }}">{{ $page }}</a>
                            @endif
                        @endforeach
                    @endif
                @endforeach
            </span>

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="rf-button-secondary">Suivant</a>
            @else
                <span aria-disabled="true" class="rf-button-secondary cursor-not-allowed opacity-50">Suivant</span>
            @endif
        </div>
    </nav>
@endif
