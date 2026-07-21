@props(['messages'])
@if ($messages)
    <ul role="alert" aria-live="polite" {{ $attributes->class('space-y-1 text-sm font-medium text-red-700') }}>
        @foreach ((array) $messages as $message)<li>{{ $message }}</li>@endforeach
    </ul>
@endif
