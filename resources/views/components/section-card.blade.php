@props(['title' => null, 'description' => null, 'as' => 'section'])
<{{ $as }} {{ $attributes->class('rf-panel overflow-hidden') }}>
    @if ($title || $description || isset($actions))
        <header class="rf-panel-heading flex flex-wrap items-start justify-between gap-3">
            <div>
                @if ($title)<h2 class="font-semibold text-slate-950">{{ $title }}</h2>@endif
                @if ($description)<p class="mt-1 text-sm leading-5 text-slate-500">{{ $description }}</p>@endif
            </div>
            @if (isset($actions))<x-action-group>{{ $actions }}</x-action-group>@endif
        </header>
    @endif
    <div class="rf-panel-body">{{ $slot }}</div>
</{{ $as }}>
