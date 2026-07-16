@props(['label', 'value', 'hint' => null])
<section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <p class="text-sm font-medium text-slate-600">{{ $label }}</p>
    <p class="mt-2 text-3xl font-bold tracking-tight text-slate-950">{{ $value }}</p>
    @if ($hint)<p class="mt-2 text-xs text-slate-500">{{ $hint }}</p>@endif
</section>
