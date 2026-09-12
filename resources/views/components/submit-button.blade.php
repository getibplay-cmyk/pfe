@props([
    'label' => __('Enregistrer'),
    'loadingLabel' => __('Traitement en cours…'),
    'variant' => 'primary',
    'icon' => null,
])
@php
    $classes = match ($variant) {
        'secondary' => 'rf-button-secondary',
        'danger' => 'rf-button-danger',
        default => 'rf-button-primary',
    };
    $resolvedIcon = $icon ?? App\Support\Ui\UiLabel::buttonIcon($label, 'save');
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
