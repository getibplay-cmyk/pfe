@props([
    'label' => 'Enregistrer',
    'loadingLabel' => 'Traitement en cours…',
    'variant' => 'primary',
    'icon' => null,
])
@php
    $classes = match ($variant) {
        'secondary' => 'rf-button-secondary',
        'danger' => 'rf-button-danger',
        default => 'rf-button-primary',
    };
    $normalizedLabel = mb_strtolower($label);
    $resolvedIcon = $icon ?? match (true) {
        str_contains($normalizedLabel, 'créer'), str_contains($normalizedLabel, 'ajouter') => 'add',
        str_contains($normalizedLabel, 'actualiser') => 'refresh',
        str_contains($normalizedLabel, 'filtrer'), str_contains($normalizedLabel, 'appliquer') => 'filter',
        str_contains($normalizedLabel, 'réinitialiser') => 'reset',
        str_contains($normalizedLabel, 'rechercher') => 'search',
        str_contains($normalizedLabel, 'générer') => 'chart',
        str_contains($normalizedLabel, 'analyser'), str_contains($normalizedLabel, 'analyse') => 'analysis',
        str_contains($normalizedLabel, 'lancer') => 'launch',
        str_contains($normalizedLabel, 'importer') => 'upload',
        str_contains($normalizedLabel, 'exporter'), str_contains($normalizedLabel, 'télécharger') => 'download',
        str_contains($normalizedLabel, 'imprimer') => 'print',
        str_contains($normalizedLabel, 'déconnecter') => 'logout',
        str_contains($normalizedLabel, 'connecter') => 'login',
        str_contains($normalizedLabel, 'désactiver') => 'disable',
        str_contains($normalizedLabel, 'supprimer') => 'delete',
        default => 'save',
    };
@endphp
<button
    type="submit"
    data-loading-submit
    {{ $attributes->class($classes) }}
>
    <span data-loading-icon aria-hidden="true"><x-icon :name="$resolvedIcon" size="xs" /></span>
    <span data-loading-spinner hidden aria-hidden="true" class="rf-spinner"></span>
    <span data-loading-label data-loading-text="{{ $loadingLabel }}">{{ $label }}</span>
</button>
