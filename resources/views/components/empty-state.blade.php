@props(['title' => 'Aucune donnée', 'description' => null])
<div {{ $attributes->class('rounded-xl border border-dashed border-slate-300 bg-slate-50 px-5 py-9 text-center') }}>
    <span aria-hidden="true" class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-slate-200 text-slate-500">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5 7h14v12H5V7Zm3-3h8v3M9 12h6" /></svg>
    </span>
    <p class="mt-3 font-semibold text-slate-900">{{ $title }}</p>
    @if ($description)<p class="mx-auto mt-1 max-w-xl text-sm leading-6 text-slate-500">{{ $description }}</p>@endif
    @if (isset($action))<div class="mt-4">{{ $action }}</div>@endif
</div>
