@props([
    'images',
    'id' => null,
    'label' => 'Galerie photos',
    'fit' => 'contain',
])
@php
    static $gallerySequence = 0;
    $gallerySequence++;
    $galleryId = $id ?? 'belkhir-space-photo-gallery-'.$gallerySequence;
    $normalizedImages = collect($images)->map(fn ($image) => [
        'src' => (string) ($image['src'] ?? ''),
        'alt' => (string) ($image['alt'] ?? 'Photo'),
    ])->filter(fn ($image) => $image['src'] !== '')->values()->all();
@endphp
<section x-data="belkhirSpaceLightbox(@js($normalizedImages))" {{ $attributes }} aria-label="{{ $label }}">
    @if ($normalizedImages !== [])
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
            @foreach ($normalizedImages as $index => $image)
                <button type="button" class="group relative overflow-hidden rounded-2xl focus-visible:ring-2 focus-visible:ring-belkhir-space-blue focus-visible:ring-offset-2" x-on:click="show({{ $index }}, $event.currentTarget)" aria-label="Agrandir : {{ $image['alt'] }}">
                    <x-photo-frame :src="$image['src']" :alt="$image['alt']" kind="gallery" :fit="$fit" class="transition duration-150 group-hover:-translate-y-0.5 group-hover:shadow-lg motion-reduce:transform-none" />
                    <span aria-hidden="true" class="absolute bottom-2 right-2 flex h-9 w-9 items-center justify-center rounded-lg bg-belkhir-space-ink/80 text-white"><x-icon name="view" /></span>
                </button>
            @endforeach
        </div>
    @else
        <x-empty-state title="Aucune photo" description="Les photos privées disponibles apparaîtront ici." />
    @endif

    <template x-teleport="body">
        <div
            x-cloak
            x-show="open"
            x-transition.opacity.duration.200ms
            class="fixed inset-0 z-[95] flex items-center justify-center bg-belkhir-space-ink/90 p-3 sm:p-6"
            x-on:click.self="close()"
            x-on:keydown.escape.window="if (open) close()"
            x-on:keydown.left.window="if (open) previous()"
            x-on:keydown.right.window="if (open) next()"
        >
            <section
                x-ref="dialog"
                role="dialog"
                aria-modal="true"
                aria-labelledby="{{ $galleryId }}-title"
                x-on:keydown.tab="trap($event, $refs.dialog)"
                class="relative flex h-full max-h-[calc(100vh-1.5rem)] w-full max-w-6xl flex-col rounded-2xl bg-white p-3 shadow-2xl sm:max-h-[calc(100vh-3rem)] sm:p-5"
            >
                <div class="flex items-center justify-between gap-4">
                    <h2 id="{{ $galleryId }}-title" class="truncate text-base font-bold text-belkhir-space-text" x-text="current?.alt || 'Photo agrandie'"></h2>
                    <x-icon-button icon="close" label="Fermer l’aperçu" variant="quiet" x-ref="close" x-on:click="close()" />
                </div>
                <div class="relative mt-3 min-h-0 flex-1 overflow-hidden rounded-xl border border-belkhir-space-border bg-slate-100">
                    <img :src="current?.src || ''" :alt="current?.alt || 'Photo agrandie'" class="h-full w-full object-contain">
                    <div x-show="hasSeveral" class="pointer-events-none absolute inset-x-2 top-1/2 flex -translate-y-1/2 justify-between">
                        <span class="pointer-events-auto"><x-icon-button icon="previous" label="Photo précédente" x-on:click="previous()" /></span>
                        <span class="pointer-events-auto"><x-icon-button icon="next" label="Photo suivante" x-on:click="next()" /></span>
                    </div>
                </div>
                <p x-show="hasSeveral" class="mt-3 text-center text-sm text-belkhir-space-muted"><span x-text="index + 1"></span> / <span x-text="images.length"></span></p>
            </section>
        </div>
    </template>
</section>
