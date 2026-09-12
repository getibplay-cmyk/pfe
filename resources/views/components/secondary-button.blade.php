@props(['icon' => null])
@php
    $resolvedIcon = $icon ?? App\Support\Ui\UiLabel::buttonIcon((string) $slot, 'save');
@endphp
<button {{ $attributes->merge(['type' => 'button'])->class('rf-button-secondary') }}>
    <x-icon :name="$resolvedIcon" size="xs" />
    {{ $slot }}
</button>
