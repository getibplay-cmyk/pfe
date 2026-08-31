@props(['message' => 'Chargement en cours…'])

<div role="status" aria-live="polite" {{ $attributes->class('flex min-h-24 items-center justify-center gap-3 rounded-xl border border-belkhir-space-border bg-belkhir-space-canvas px-5 py-8 text-sm font-medium text-belkhir-space-muted') }}>
    <x-spinner :announce="false" size="md" class="text-belkhir-space-blue" />
    <span>{{ $message }}</span>
</div>
