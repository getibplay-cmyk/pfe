@props([
    'icon',
    'label',
    'href' => null,
    'variant' => 'neutral',
    'type' => 'button',
    'disabled' => false,
])
@php
    $buttonClass = match ($variant) {
        'primary' => 'border-belkhir-space-blue bg-belkhir-space-blue text-white hover:border-belkhir-space-blue-hover hover:bg-belkhir-space-blue-hover',
        'danger' => 'border-red-200 bg-white text-belkhir-space-danger hover:border-red-300 hover:bg-red-50',
        'success' => 'border-emerald-200 bg-white text-belkhir-space-success hover:border-emerald-300 hover:bg-emerald-50',
        'quiet' => 'border-transparent bg-transparent text-belkhir-space-muted hover:bg-slate-100 hover:text-belkhir-space-text',
        default => 'border-belkhir-space-border bg-white text-belkhir-space-muted shadow-sm hover:border-slate-400 hover:bg-slate-50 hover:text-belkhir-space-blue',
    };
    $classes = "inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border transition duration-150 focus-visible:ring-2 focus-visible:ring-belkhir-space-blue focus-visible:ring-offset-2 active:translate-y-px disabled:pointer-events-none disabled:opacity-50 {$buttonClass}";
@endphp
<span class="group relative inline-flex">
    @if ($href !== null)
        <a
            @unless($disabled) href="{{ $href }}" @endunless
            aria-label="{{ $label }}"
            title="{{ $label }}"
            @if($disabled) aria-disabled="true" tabindex="-1" @endif
            {{ $attributes->class($classes) }}
        ><x-icon :name="$icon" /></a>
    @else
        <button
            type="{{ $type }}"
            aria-label="{{ $label }}"
            title="{{ $label }}"
            @disabled($disabled)
            {{ $attributes->class($classes) }}
        ><x-icon :name="$icon" /></button>
    @endif
    <span role="tooltip" class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 hidden -translate-x-1/2 whitespace-nowrap rounded-lg bg-belkhir-space-ink px-2.5 py-1.5 text-xs font-semibold text-white shadow-lg group-hover:block group-focus-within:block">
        {{ $label }}
    </span>
</span>
