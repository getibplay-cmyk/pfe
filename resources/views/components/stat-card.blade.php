@props(['label', 'value', 'hint' => null, 'tone' => 'brand'])
<section {{ $attributes->class('rf-panel relative overflow-hidden p-5') }}>
    <span aria-hidden="true" @class(['absolute inset-y-0 left-0 w-1', 'bg-atlas-blue' => $tone === 'brand', 'bg-atlas-success' => $tone === 'success', 'bg-atlas-orange' => $tone === 'warning', 'bg-atlas-danger' => $tone === 'danger'])></span>
    <p class="text-sm font-medium text-slate-600">{{ $label }}</p>
    <p class="mt-2 text-3xl font-bold tracking-tight text-slate-950">{{ $value }}</p>
    @if ($hint)<p class="mt-2 text-xs leading-5 text-slate-500">{{ $hint }}</p>@endif
</section>
