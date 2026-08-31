@props(['item', 'surface' => 'desktop'])
@php($active = request()->routeIs(...(array) $item['pattern']))
<a
    href="{{ route($item['route']) }}"
    data-nav-key="{{ $item['key'] }}"
    data-nav-surface="{{ $surface }}"
    @class([
        'group relative flex min-h-10 items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2',
        'bg-atlas-blue text-white shadow-sm before:absolute before:inset-y-2 before:start-0 before:w-0.5 before:rounded-full before:bg-atlas-orange-soft' => $active,
        'text-slate-300 hover:bg-white/10 hover:text-white' => ! $active && in_array($surface, ['desktop', 'mobile'], true),
    ])
    @if($active) aria-current="page" @endif
>
    <x-navigation-icon :name="$item['key']" @class(['text-atlas-orange-soft' => $active, 'text-brand-300' => ! $active]) />
    <span>{{ $item['label'] }}</span>
</a>
