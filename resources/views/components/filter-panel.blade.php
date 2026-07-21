@props(['title' => 'Filtres'])
<section aria-label="{{ $title }}" {{ $attributes->class('rf-panel rf-panel-body') }}>
    <div class="mb-4 flex items-center justify-between gap-3">
        <h2 class="text-sm font-semibold text-slate-900">{{ $title }}</h2>
        @if (isset($aside))<div class="text-xs text-slate-500">{{ $aside }}</div>@endif
    </div>
    {{ $slot }}
</section>
