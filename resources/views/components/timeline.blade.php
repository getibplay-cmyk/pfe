@props(['label' => 'Historique'])
<ol aria-label="{{ $label }}" {{ $attributes->class('relative space-y-5 border-s border-slate-200 ps-5') }}>{{ $slot }}</ol>
