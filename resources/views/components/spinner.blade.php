@props(['label' => 'Traitement en cours…', 'size' => 'sm', 'announce' => true])
@php
    $sizeClass = match ($size) {
        'lg' => 'h-7 w-7',
        'md' => 'h-5 w-5',
        default => 'h-4 w-4',
    };
@endphp
<span @if($announce) role="status" @else aria-hidden="true" @endif {{ $attributes->class('inline-flex items-center justify-center') }}>
    <svg aria-hidden="true" focusable="false" class="{{ $sizeClass }} animate-spin" viewBox="0 0 24 24" fill="none">
        <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" />
        <path class="opacity-90" fill="currentColor" d="M21 12a9 9 0 0 0-9-9v3a6 6 0 0 1 6 6h3Z" />
    </svg>
    @if($announce)<span class="sr-only">{{ $label }}</span>@endif
</span>
