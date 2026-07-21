@props(['label' => 'Résultats', 'mobileHint' => true])
<section aria-label="{{ $label }}" {{ $attributes->class('rf-panel overflow-hidden') }}>
    @if ($mobileHint)<p class="rf-mobile-scroll-hint">Faites défiler horizontalement pour consulter toutes les colonnes.</p>@endif
    <div class="rf-table-scroll">{{ $slot }}</div>
    @if (isset($footer))<footer class="border-t border-slate-100 px-4 py-3 sm:px-6">{{ $footer }}</footer>@endif
</section>
