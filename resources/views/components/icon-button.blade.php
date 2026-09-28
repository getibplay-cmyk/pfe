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
        'primary' => 'border-sanad-pilot-blue bg-sanad-pilot-blue text-white hover:border-sanad-pilot-blue-hover hover:bg-sanad-pilot-blue-hover',
        'danger' => 'border-red-200 bg-white text-sanad-pilot-danger hover:border-red-300 hover:bg-red-50',
        'success' => 'border-emerald-200 bg-white text-sanad-pilot-success hover:border-emerald-300 hover:bg-emerald-50',
        'quiet' => 'border-transparent bg-transparent text-sanad-pilot-muted hover:bg-slate-100 hover:text-sanad-pilot-text',
        default => 'border-sanad-pilot-border bg-white text-sanad-pilot-muted shadow-sm hover:border-slate-400 hover:bg-slate-50 hover:text-sanad-pilot-blue',
    };
    $classes = "inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border transition duration-150 focus-visible:ring-2 focus-visible:ring-sanad-pilot-blue focus-visible:ring-offset-2 active:translate-y-px disabled:pointer-events-none disabled:opacity-50 {$buttonClass}";
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
    <span role="tooltip" class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 hidden -translate-x-1/2 whitespace-nowrap rounded-lg bg-sanad-pilot-ink px-2.5 py-1.5 text-xs font-semibold text-white shadow-lg group-hover:block group-focus-within:block">
        {{ $label }}
    </span>
</span>
