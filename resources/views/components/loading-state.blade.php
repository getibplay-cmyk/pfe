@props(['message' => __('Chargement en cours…')])

<div role="status" aria-live="polite" {{ $attributes->class('flex min-h-24 items-center justify-center gap-3 rounded-xl border border-sanad-pilot-border bg-sanad-pilot-canvas px-5 py-8 text-sm font-medium text-sanad-pilot-muted') }}>
    <x-spinner :announce="false" size="md" class="text-sanad-pilot-blue" />
    <span>{{ $message }}</span>
</div>
