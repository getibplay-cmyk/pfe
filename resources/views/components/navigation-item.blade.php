@props(['item', 'surface' => 'desktop'])
@php($active = request()->routeIs($item['pattern']))
<a
    href="{{ route($item['route']) }}"
    data-nav-key="{{ $item['key'] }}"
    data-nav-surface="{{ $surface }}"
    @class([
        'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400',
        'bg-white text-slate-950 shadow-sm' => $active && $surface === 'desktop',
        'text-slate-300 hover:bg-white/10 hover:text-white' => ! $active && $surface === 'desktop',
        'bg-indigo-50 text-indigo-800' => $active && $surface === 'mobile',
        'text-slate-700 hover:bg-slate-100' => ! $active && $surface === 'mobile',
    ])
    @if($active) aria-current="page" @endif
>
    <span aria-hidden="true" @class(['h-2 w-2 rounded-full', 'bg-indigo-500' => $active, 'bg-slate-500 group-hover:bg-slate-300' => ! $active])></span>
    <span>{{ $item['label'] }}</span>
</a>
