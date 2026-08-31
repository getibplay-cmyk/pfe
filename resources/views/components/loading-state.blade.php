@props(['message' => 'Chargement en cours…'])

<div role="status" aria-live="polite" {{ $attributes->class('flex min-h-24 items-center justify-center gap-3 rounded-xl border border-atlas-border bg-atlas-canvas px-5 py-8 text-sm font-medium text-atlas-muted') }}>
    <svg aria-hidden="true" class="h-5 w-5 animate-spin text-atlas-blue" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" /><path class="opacity-90" fill="currentColor" d="M21 12a9 9 0 0 0-9-9v3a6 6 0 0 1 6 6h3Z" /></svg>
    <span>{{ $message }}</span>
</div>
