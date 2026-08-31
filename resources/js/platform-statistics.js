import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    Legend,
    LinearScale,
    Tooltip,
} from 'chart.js';
import { atlasCartesianScales, atlasChartColors } from './atlas-chart-theme.js';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip, Legend);

export function platformChartConfiguration(labels, values, color) {
    return {
        type: 'bar',
        data: {
            labels,
            datasets: [{ data: values, backgroundColor: color, borderRadius: 6, maxBarThickness: 44 }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            plugins: {
                legend: { display: false },
                tooltip: { intersect: false },
            },
            scales: atlasCartesianScales({ integer: true }),
        },
    };
}

export function renderPlatformBar(canvas, labels, values, color, ChartClass = Chart) {
    if (typeof HTMLCanvasElement === 'undefined' || ! (canvas instanceof HTMLCanvasElement)) return null;

    ChartClass.getChart(canvas)?.destroy();

    return new ChartClass(canvas, platformChartConfiguration(labels, values, color));
}

export function initializePlatformStatistics() {
    document.querySelectorAll('[data-platform-statistics]').forEach((root) => {
        const source = root.querySelector('[data-platform-statistics-payload]');
        if (! source) return;

        let payload;
        try {
            payload = JSON.parse(source.textContent ?? '{}');
        } catch {
            return;
        }

        const colors = atlasChartColors(root);

        renderPlatformBar(
            root.querySelector('[data-platform-chart="states"]'),
            payload.states?.labels ?? [],
            payload.states?.values ?? [],
            colors.blue,
        );
        renderPlatformBar(
            root.querySelector('[data-platform-chart="activity"]'),
            payload.activity?.labels ?? [],
            payload.activity?.values ?? [],
            colors.orange,
        );
    });
}
