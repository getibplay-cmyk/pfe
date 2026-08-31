@props([
    'src' => null,
    'alt' => '',
    'kind' => 'evidence',
    'fit' => null,
    'emptyLabel' => 'Aucune photo disponible',
])
@php
    $ratioClass = match ($kind) {
        'square' => 'aspect-square',
        default => 'aspect-[4/3]',
    };
    $resolvedFit = $fit ?? ($kind === 'gallery' || $kind === 'vehicle' ? 'cover' : 'contain');
    $fitClass = $resolvedFit === 'cover' ? 'object-cover' : 'object-contain';
@endphp
<figure x-data="{ failed: false }" {{ $attributes->class(["relative overflow-hidden rounded-2xl border border-belkhir-space-border bg-slate-100 {$ratioClass}"]) }}>
    @if ($src)
        <img
            x-show="! failed"
            src="{{ $src }}"
            alt="{{ $alt }}"
            loading="lazy"
            class="h-full w-full {{ $fitClass }}"
            x-on:error="failed = true"
        >
        @if (isset($overlay))
            <span x-show="! failed" class="contents">{{ $overlay }}</span>
        @endif
        <div x-cloak x-show="failed" role="status" class="absolute inset-0 flex flex-col items-center justify-center gap-2 px-4 text-center text-sm text-belkhir-space-muted">
            <x-icon name="error" size="lg" class="text-belkhir-space-danger" />
            <span>La photo ne peut pas être affichée.</span>
        </div>
    @else
        <div class="absolute inset-0 flex flex-col items-center justify-center gap-2 px-4 text-center text-sm text-belkhir-space-muted">
            <x-icon name="image" size="lg" />
            <span>{{ $emptyLabel }}</span>
        </div>
    @endif
</figure>
