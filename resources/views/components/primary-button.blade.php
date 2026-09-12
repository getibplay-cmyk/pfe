@props(['icon' => null])
@php
    $resolvedIcon = $icon ?? App\Support\Ui\UiLabel::buttonIcon((string) $slot, 'save');
@endphp
<button {{ $attributes->merge(['type' => 'submit'])->class('rf-button-primary') }}>
    <x-icon :name="$resolvedIcon" size="xs" />
    {{ $slot }}
</button>
