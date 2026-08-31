@props(['items' => []])

@if (count($items) > 1)
    <nav aria-label="Fil d’Ariane" {{ $attributes }}>
        <ol class="flex flex-wrap items-center gap-2 text-sm text-atlas-muted">
            @foreach ($items as $item)
                <li class="flex min-w-0 items-center gap-2">
                    @if (! $loop->first)
                        <svg aria-hidden="true" class="h-4 w-4 shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path d="m7.5 4 6 6-6 6-1.5-1.5L10.5 10 6 5.5 7.5 4Z" /></svg>
                    @endif
                    @if ($loop->last || empty($item['url']))
                        <span class="truncate font-medium text-atlas-text" @if($loop->last) aria-current="page" @endif>{{ $item['label'] }}</span>
                    @else
                        <a class="truncate font-medium text-atlas-blue hover:text-atlas-blue-hover hover:underline" href="{{ $item['url'] }}">{{ $item['label'] }}</a>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
