@props([
    'message',
    'variant' => 'danger',
    'type' => 'submit',
    'title' => __('Confirmer cette action'),
    'resource' => __('Élément sélectionné'),
    'confirmLabel' => __('Confirmer'),
    'loadingLabel' => __('Traitement en cours…'),
    'icon' => null,
])
@php
    $resolvedIcon = $icon ?? App\Support\Ui\UiLabel::buttonIcon((string) $slot, 'warning');
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
