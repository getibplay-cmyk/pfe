@props(['variant' => 'card', 'label' => 'Chargement du contenu…'])
<div role="status" aria-live="polite" {{ $attributes->class('animate-pulse overflow-hidden rounded-xl border border-belkhir-space-border bg-white p-4') }}>
    @if ($variant === 'chart')
        <div class="h-4 w-40 rounded bg-slate-200"></div>
        <div class="mt-5 flex h-52 items-end gap-3" aria-hidden="true">
            @foreach ([45, 72, 58, 86, 64, 78] as $height)
                <span class="flex-1 rounded-t bg-slate-100" style="height: {{ $height }}%"></span>
            @endforeach
        </div>
    @elseif ($variant === 'table')
        <div class="h-10 rounded bg-slate-100" aria-hidden="true"></div>
        @foreach (range(1, 4) as $row)
            <div class="mt-3 grid grid-cols-4 gap-3" aria-hidden="true">
                <span class="h-4 rounded bg-slate-200"></span><span class="h-4 rounded bg-slate-100"></span><span class="h-4 rounded bg-slate-100"></span><span class="h-4 rounded bg-slate-200"></span>
            </div>
        @endforeach
    @else
        <div class="h-4 w-24 rounded bg-slate-200" aria-hidden="true"></div>
        <div class="mt-4 h-8 w-32 rounded bg-slate-100" aria-hidden="true"></div>
        <div class="mt-3 h-3 w-full rounded bg-slate-100" aria-hidden="true"></div>
    @endif
    <span class="sr-only">{{ $label }}</span>
</div>
