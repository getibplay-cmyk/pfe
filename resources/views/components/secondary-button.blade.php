@props(['icon' => null])
@php
    $normalizedLabel = mb_strtolower(trim(strip_tags((string) $slot)));
    $resolvedIcon = $icon ?? match (true) {
        str_contains($normalizedLabel, 'cré') || str_contains($normalizedLabel, 'ajout') => 'add',
        str_contains($normalizedLabel, 'rejet') => 'close',
        str_contains($normalizedLabel, 'allou') => 'payment',
        default => 'save',
    };
@endphp
<button {{ $attributes->merge(['type' => 'button'])->class('rf-button-secondary') }}>
    <x-icon :name="$resolvedIcon" size="xs" />
    {{ $slot }}
</button>
