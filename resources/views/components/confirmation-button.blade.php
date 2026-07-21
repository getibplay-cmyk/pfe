@props(['message', 'variant' => 'danger', 'type' => 'submit'])
<button type="{{ $type }}" x-on:click="if (! window.confirm(@js($message))) $event.preventDefault()" {{ $attributes->class($variant === 'danger' ? 'rf-button-danger' : 'rf-button-secondary') }}>{{ $slot }}</button>
