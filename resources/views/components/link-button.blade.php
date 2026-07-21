@props(['variant' => 'secondary'])
<a {{ $attributes->class($variant === 'primary' ? 'rf-button-primary' : 'rf-button-secondary') }}>{{ $slot }}</a>
