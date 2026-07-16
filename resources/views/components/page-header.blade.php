@props(['title', 'eyebrow' => null, 'description' => null])
<div class="flex flex-wrap items-start justify-between gap-4">
    <div class="min-w-0">
        @if ($eyebrow)<p class="text-sm font-medium text-indigo-700">{{ $eyebrow }}</p>@endif
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">{{ $title }}</h1>
        @if ($description)<p class="mt-2 max-w-3xl text-sm text-slate-600">{{ $description }}</p>@endif
    </div>
    @if (isset($actions))<div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>@endif
</div>
