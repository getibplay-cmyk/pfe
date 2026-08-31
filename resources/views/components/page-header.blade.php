@props(['title', 'eyebrow' => null, 'description' => null, 'breadcrumbs' => []])
<header {{ $attributes->class('space-y-4') }}>
    <x-breadcrumbs :items="$breadcrumbs" />
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0 border-s-4 border-belkhir-space-orange ps-4">
            @if ($eyebrow)<p class="text-xs font-bold uppercase tracking-[0.14em] text-belkhir-space-blue">{{ $eyebrow }}</p>@endif
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-belkhir-space-text sm:text-3xl">{{ $title }}</h1>
            @if ($description)<p class="mt-2 max-w-3xl text-sm leading-6 text-belkhir-space-muted">{{ $description }}</p>@endif
        </div>
        @if (isset($actions))<x-action-group>{{ $actions }}</x-action-group>@endif
    </div>
</header>
