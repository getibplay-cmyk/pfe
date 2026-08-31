@props(['icon' => null])
@php
    $normalizedLabel = mb_strtolower(trim(strip_tags((string) $slot)));
    $resolvedIcon = $icon ?? match (true) {
        str_contains($normalizedLabel, 'recherch') => 'search',
        str_contains($normalizedLabel, 'filtr') => 'filter',
        str_contains($normalizedLabel, 'export') => 'download',
        str_contains($normalizedLabel, 'génér') => 'chart',
        str_contains($normalizedLabel, 'cré') || str_contains($normalizedLabel, 'ajout') => 'add',
        str_contains($normalizedLabel, 'statut') => 'refresh',
        default => 'save',
    };
@endphp
<button {{ $attributes->merge(['type' => 'submit'])->class('rf-button-primary') }}>
    <x-icon :name="$resolvedIcon" size="xs" />
    {{ $slot }}
</button>
