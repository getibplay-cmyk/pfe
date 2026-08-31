@props([
    'id',
    'title',
    'description',
    'chart',
    'series',
    'period',
    'unit' => 'Nombre',
])

<x-section-card :title="$title" :description="$description">
    <p class="mb-4 text-xs font-medium text-slate-500">
        Période du {{ $period['from'] }} au {{ $period['to'] }} · unité : {{ mb_strtolower($unit) }}
    </p>
    @if ($series['total'] > 0)
        <div class="rf-chart-surface h-72 overflow-hidden" data-chart-shell data-chart-ready="false" aria-busy="true">
            <x-skeleton variant="chart" :label="'Chargement du graphique '.$title.'…'" class="absolute inset-3 z-10 motion-reduce:animate-none" data-chart-skeleton />
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
                <caption class="sr-only">{{ $title }} — données du graphique</caption>
                <thead>
                    <tr class="border-b border-slate-200">
                        <th scope="col" class="py-2 text-left">État</th>
                        <th scope="col" class="py-2 text-right">{{ $unit }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($series['labels'] as $index => $label)
                        <tr class="border-t border-slate-100">
                            <th scope="row" class="py-2.5 text-left font-medium text-slate-700">{{ $label }}</th>
                            <td class="py-2.5 text-right font-semibold text-slate-950">{{ App\Support\Ui\BusinessNumber::integer($series['values'][$index]) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <x-empty-state title="Aucune donnée sur cette période" description="Le graphique apparaîtra dès qu’une activité correspondante sera enregistrée." />
    @endif
</x-section-card>
