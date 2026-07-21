@props(['paginator'])
<p {{ $attributes->class('text-sm text-slate-500') }} aria-live="polite">
    {{ $paginator->total() }} résultat(s)
    @if ($paginator->total() > 0) — affichage de {{ $paginator->firstItem() }} à {{ $paginator->lastItem() }} @endif
</p>
