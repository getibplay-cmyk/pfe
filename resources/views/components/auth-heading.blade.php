@props(['eyebrow', 'title', 'description', 'icon' => 'shield'])

<header class="relative">
    <div class="mb-5 flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-50 text-belkhir-space-blue ring-1 ring-brand-100" aria-hidden="true">
        @switch($icon)
            @case('mail')
                <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false"><rect width="18" height="14" x="3" y="5" rx="2" /><path d="m3 7 9 6 9-6" /></svg>
                @break
            @case('key')
                <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false"><circle cx="7.5" cy="15.5" r="4.5" /><path d="m10.7 12.3 8-8M15 8l2 2m-5-5 2 2" /></svg>
                @break
            @case('check')
                <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M20 11.1V12a8 8 0 1 1-4.7-7.3" /><path d="m9 11 3 3L22 4" /></svg>
                @break
            @default
                <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10" /><path d="m9 12 2 2 4-4" /></svg>
        @endswitch
    </div>
    <p class="text-xs font-bold uppercase tracking-[0.18em] text-belkhir-space-blue">{{ $eyebrow }}</p>
    <h1 class="mt-2 text-2xl font-bold tracking-tight text-belkhir-space-text sm:text-3xl">{{ $title }}</h1>
    <p class="mt-3 text-sm leading-6 text-belkhir-space-muted">{{ $description }}</p>
</header>
