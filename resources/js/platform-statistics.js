import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    Legend,
    LinearScale,
    Tooltip,
} from 'chart.js';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip, Legend);

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    plugins: {
        legend: { display: false },
        tooltip: { intersect: false },
    },
    scales: {
        x: { grid: { display: false } },
        y: { beginAtZero: true, ticks: { precision: 0 } },
    },
};

function renderBar(canvas, labels, values, color) {
    if (! (canvas instanceof HTMLCanvasElement)) return;

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [{ data: values, backgroundColor: color, borderRadius: 6 }],
        },
        options: chartOptions,
    });
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

        renderBar(
            root.querySelector('[data-platform-chart="states"]'),
            payload.states?.labels ?? [],
            payload.states?.values ?? [],
            '#1d4ed8',
        );
        renderBar(
            root.querySelector('[data-platform-chart="activity"]'),
            payload.activity?.labels ?? [],
            payload.activity?.values ?? [],
            '#f97316',
        );
    });
}
