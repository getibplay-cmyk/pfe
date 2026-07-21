@props(['item', 'surface' => 'desktop'])
@php($active = request()->routeIs($item['pattern']))
<a
    href="{{ route($item['route']) }}"
    data-nav-key="{{ $item['key'] }}"
    data-nav-surface="{{ $surface }}"
    @class([
        'group flex min-h-10 items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2',
        'bg-white/10 text-white ring-1 ring-white/10' => $active && $surface === 'desktop',
        'text-slate-300 hover:bg-white/5 hover:text-white' => ! $active && $surface === 'desktop',
        'bg-brand-50 text-brand-900' => $active && $surface === 'mobile',
        'text-slate-700 hover:bg-slate-100 hover:text-slate-950' => ! $active && $surface === 'mobile',
    ])
    @if($active) aria-current="page" @endif
>
    <x-navigation-icon :name="$item['key']" @class(['text-brand-300' => $surface === 'desktop', 'text-brand-700' => $surface === 'mobile']) />
    <span>{{ $item['label'] }}</span>
</a>
