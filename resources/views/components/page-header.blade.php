@props(['title', 'eyebrow' => null, 'description' => null])
<header {{ $attributes->class('flex flex-wrap items-start justify-between gap-4') }}>
    <div class="min-w-0">
        @if ($eyebrow)<p class="text-xs font-bold uppercase tracking-[0.14em] text-brand-700">{{ $eyebrow }}</p>@endif
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">{{ $title }}</h1>
        @if ($description)<p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ $description }}</p>@endif
    </div>
    @if (isset($actions))<x-action-group>{{ $actions }}</x-action-group>@endif
</header>
