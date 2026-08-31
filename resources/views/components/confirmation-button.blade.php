@props([
    'message',
    'variant' => 'danger',
    'type' => 'submit',
    'title' => 'Confirmer cette action',
    'resource' => 'Élément sélectionné',
    'confirmLabel' => 'Confirmer',
    'loadingLabel' => 'Traitement en cours…',
    'icon' => null,
])
@php
    $normalizedLabel = mb_strtolower(trim(strip_tags((string) $slot)));
    $resolvedIcon = $icon ?? match (true) {
        str_contains($normalizedLabel, 'enregistr') => 'save',
        str_contains($normalizedLabel, 'cré'), str_contains($normalizedLabel, 'ajout') => 'add',
        str_contains($normalizedLabel, 'actualis') => 'refresh',
        str_contains($normalizedLabel, 'génér') => 'chart',
        str_contains($normalizedLabel, 'analys') => 'analysis',
        str_contains($normalizedLabel, 'import') => 'upload',
        str_contains($normalizedLabel, 'export'), str_contains($normalizedLabel, 'télécharg') => 'download',
        str_contains($normalizedLabel, 'paiement') => 'payment',
        str_contains($normalizedLabel, 'abonnement') => 'add',
        str_contains($normalizedLabel, 'supprim') => 'delete',
        str_contains($normalizedLabel, 'désactiv') => 'disable',
        default => 'warning',
    };
@endphp
<button
    type="{{ $type }}"
    data-loading-submit
    x-on:click.prevent="$dispatch('belkhir-space-confirm-request', {
        form: $el.form,
        submitter: $el,
        title: @js($title),
        resource: @js($resource),
        consequence: @js($message),
        confirmLabel: @js($confirmLabel),
    })"
    {{ $attributes->class($variant === 'danger' ? 'rf-button-danger' : 'rf-button-secondary') }}
>
    <span data-loading-icon aria-hidden="true"><x-icon :name="$resolvedIcon" size="xs" /></span>
    <span data-loading-spinner hidden aria-hidden="true" class="rf-spinner"></span>
    <span data-loading-label data-loading-text="{{ $loadingLabel }}">{{ $slot }}</span>
</button>
