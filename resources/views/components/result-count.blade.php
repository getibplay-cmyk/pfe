@props(['paginator'])
<p {{ $attributes->class('text-sm text-slate-500') }} aria-live="polite">
    {{ App\Support\Ui\BusinessNumber::count($paginator->total(), 'résultat') }}
    @if ($paginator->total() > 0) — affichage de {{ App\Support\Ui\BusinessNumber::integer($paginator->firstItem()) }} à {{ App\Support\Ui\BusinessNumber::integer($paginator->lastItem()) }} @endif
</p>
