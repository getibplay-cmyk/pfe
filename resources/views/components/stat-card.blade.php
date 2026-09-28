@props(['label', 'value', 'hint' => null, 'tone' => 'brand', 'icon' => null])
<section {{ $attributes->class('rf-panel rf-stat-card relative overflow-hidden p-5') }}>
    <span aria-hidden="true" @class(['absolute inset-y-0 start-0 w-1', 'bg-sanad-pilot-blue' => $tone === 'brand', 'bg-sanad-pilot-success' => $tone === 'success', 'bg-sanad-pilot-orange' => $tone === 'warning', 'bg-sanad-pilot-danger' => $tone === 'danger'])></span>
    <div class="flex items-start justify-between gap-3">
        <p class="text-sm font-medium text-slate-600">{{ $label }}</p>
        @if ($icon)
            <span aria-hidden="true" @class(['flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', 'bg-brand-50 text-sanad-pilot-blue' => $tone === 'brand', 'bg-emerald-50 text-sanad-pilot-success' => $tone === 'success', 'bg-sanad-pilot-orange-soft text-sanad-pilot-orange' => $tone === 'warning', 'bg-red-50 text-sanad-pilot-danger' => $tone === 'danger'])>
                <x-icon :name="$icon" />
            </span>
        @endif
    </div>
    <p class="mt-3 text-3xl font-bold tracking-tight text-slate-950">{{ $value }}</p>
    @if ($hint)<p class="mt-2 text-xs leading-5 text-slate-500">{{ $hint }}</p>@endif
</section>
