@props(['label', 'value', 'hint' => null, 'tone' => 'brand', 'icon' => null])
<section {{ $attributes->class('rf-panel relative overflow-hidden p-5') }}>
    <span aria-hidden="true" @class(['absolute inset-y-0 left-0 w-1', 'bg-belkhir-space-blue' => $tone === 'brand', 'bg-belkhir-space-success' => $tone === 'success', 'bg-belkhir-space-orange' => $tone === 'warning', 'bg-belkhir-space-danger' => $tone === 'danger'])></span>
    <div class="flex items-start justify-between gap-3">
        <p class="text-sm font-medium text-slate-600">{{ $label }}</p>
        @if ($icon)
            <span aria-hidden="true" @class(['flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', 'bg-brand-50 text-belkhir-space-blue' => $tone === 'brand', 'bg-emerald-50 text-belkhir-space-success' => $tone === 'success', 'bg-belkhir-space-orange-soft text-belkhir-space-orange' => $tone === 'warning', 'bg-red-50 text-belkhir-space-danger' => $tone === 'danger'])>
                <x-icon :name="$icon" />
            </span>
        @endif
    </div>
    <p class="mt-2 text-3xl font-bold tracking-tight text-slate-950">{{ $value }}</p>
    @if ($hint)<p class="mt-2 text-xs leading-5 text-slate-500">{{ $hint }}</p>@endif
</section>
