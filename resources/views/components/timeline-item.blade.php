@props(['title', 'meta' => null, 'active' => false])
<li class="relative">
    <span aria-hidden="true" @class(['absolute -start-[1.63rem] top-1 h-3 w-3 rounded-full ring-4 ring-white', 'bg-brand-700' => $active, 'bg-slate-300' => ! $active])></span>
    <p class="text-sm font-semibold text-slate-900">{{ $title }}</p>
    @if ($meta)<p class="mt-0.5 text-xs text-slate-500">{{ $meta }}</p>@endif
    @if ($slot->isNotEmpty())<div class="mt-2 text-sm text-slate-600">{{ $slot }}</div>@endif
</li>
