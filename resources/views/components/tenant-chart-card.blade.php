@props([
    'id',
    'title',
    'description',
    'chart',
    'series',
    'period',
    'unit' => __('Nombre'),
])

<x-section-card :title="$title" :description="$description">
    <p class="mb-4 text-xs font-medium text-slate-500">
        {{ __('Période du') }} {{ $period['from'] }} {{ __('au') }} {{ $period['to'] }} {{ __('· unité :') }} {{ mb_strtolower($unit) }}
    </p>
    @if ($series['total'] > 0)
        <div class="rf-chart-surface h-72 overflow-hidden" data-chart-shell data-chart-ready="false" aria-busy="true">
            <x-skeleton variant="chart" :label="__('Chargement du graphique ').$title.'…'" class="absolute inset-3 z-10 motion-reduce:animate-none" data-chart-skeleton />
            <canvas
                class="opacity-0 transition-opacity duration-500 motion-reduce:transition-none"
                role="img"
                data-tenant-chart="{{ $chart }}"
                aria-label="{{ $title }}. {{ $description }}"
                aria-describedby="{{ $id }}-table"
            ></canvas>
        </div>
        <div id="{{ $id }}-table" class="mt-5 overflow-x-auto">
            <table class="w-full text-sm">
                <caption class="sr-only">{{ $title }} {{ __('— données du graphique') }}</caption>
                <thead>
                    <tr class="border-b border-slate-200">
                        <th scope="col" class="py-2 text-start">{{ __('État') }}</th>
                        <th scope="col" class="py-2 text-end">{{ $unit }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($series['labels'] as $index => $label)
                        <tr class="border-t border-slate-100">
                            <th scope="row" class="py-2.5 text-start font-medium text-slate-700">{{ $label }}</th>
                            <td class="py-2.5 text-end font-semibold text-slate-950">{{ App\Support\Ui\BusinessNumber::integer($series['values'][$index]) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <x-empty-state :title="__('Aucune donnée sur cette période')" :description="__('Le graphique apparaîtra dès qu’une activité correspondante sera enregistrée.')" />
    @endif
</x-section-card>
