@props(['paginator'])
<p class="text-sm text-slate-500">
    {{ $paginator->total() }} résultat(s)
    @if ($paginator->total() > 0) — affichage {{ $paginator->firstItem() }} à {{ $paginator->lastItem() }} @endif
</p>
