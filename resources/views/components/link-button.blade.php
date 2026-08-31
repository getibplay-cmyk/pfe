@props(['variant' => 'secondary'])
@php
    $classes = match ($variant) {
        'primary' => 'rf-button-primary',
        'quiet' => 'rf-button-quiet',
        'danger' => 'rf-button-danger',
        default => 'rf-button-secondary',
    };
@endphp
<a {{ $attributes->class($classes) }}>{{ $slot }}</a>
