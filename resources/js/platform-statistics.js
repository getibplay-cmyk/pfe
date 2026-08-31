import {
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    DoughnutController,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
} from 'chart.js';
import { belkhirSpaceCartesianScales, belkhirSpaceChartColors } from './belkhir-space-chart-theme.js';
import { formatBusinessInteger } from './business-number.js';

Chart.register(
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    DoughnutController,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
);

export function platformChartsPreferReducedMotion() {
    return typeof window !== 'undefined'
        && typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function belkhirSpaceAnimation() {
    return platformChartsPreferReducedMotion()
        ? false
        : { duration: 500, easing: 'easeOutQuart' };
}

function belkhirSpaceTooltip() {
    return {
        intersect: false,
        callbacks: {
            label: (context) => `${context.label || context.dataset.label}: ${formatBusinessInteger(context.raw)}`,
        },
    };
}

export function platformChartConfiguration(labels, values, color, {
    horizontal = false,
    label = 'Nombre',
    maximum = null,
} = {}) {
    return {
        type: 'bar',
        data: {
            labels,
            datasets: [{ label, data: values, backgroundColor: color, borderRadius: 6, maxBarThickness: 44 }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: horizontal ? 'y' : 'x',
            animation: belkhirSpaceAnimation(),
            plugins: {
                legend: { display: false },
                tooltip: belkhirSpaceTooltip(),
            },
            scales: belkhirSpaceCartesianScales({ integer: true, horizontal, maximum }),
        },
    };
}

export function platformLineConfiguration(labels, values, color) {
    return {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: 'Analyses',
                data: values,
                borderColor: color,
                backgroundColor: color,
                borderWidth: 3,
                pointRadius: 4,
                pointHoverRadius: 6,
                tension: 0.3,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: belkhirSpaceAnimation(),
            plugins: {
                legend: { display: false },
                tooltip: belkhirSpaceTooltip(),
            },
            scales: belkhirSpaceCartesianScales({ integer: true }),
        },
    };
}

export function platformDoughnutConfiguration(labels, values, colors) {
    return {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{ label: 'Nombre', data: values, backgroundColor: colors, borderWidth: 0 }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '68%',
            animation: belkhirSpaceAnimation(),
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { usePointStyle: true, boxWidth: 8, padding: 18 },
                },
                tooltip: belkhirSpaceTooltip(),
            },
        },
    };
}

function revealPlatformChart(canvas) {
    canvas.classList?.remove('opacity-0');
    const shell = canvas.closest?.('[data-chart-shell]');
    const skeleton = shell?.querySelector?.('[data-chart-skeleton]');

    if (skeleton) {
        skeleton.hidden = true;
        skeleton.setAttribute('aria-hidden', 'true');
    }

    shell?.setAttribute?.('data-chart-ready', 'true');
    shell?.setAttribute?.('aria-busy', 'false');
}

function renderPlatformChart(canvas, configuration, ChartClass = Chart) {
    if (typeof HTMLCanvasElement === 'undefined' || ! (canvas instanceof HTMLCanvasElement)) return null;

    ChartClass.getChart(canvas)?.destroy();
    const chart = new ChartClass(canvas, configuration);
    revealPlatformChart(canvas);

    return chart;
}

export function renderPlatformBar(canvas, labels, values, color, ChartClass = Chart, options = {}) {
    return renderPlatformChart(
        canvas,
        platformChartConfiguration(labels, values, color, options),
        ChartClass,
    );
}

export function renderPlatformLine(canvas, labels, values, color, ChartClass = Chart) {
    return renderPlatformChart(canvas, platformLineConfiguration(labels, values, color), ChartClass);
}

export function renderPlatformDoughnut(canvas, labels, values, colors, ChartClass = Chart) {
    return renderPlatformChart(canvas, platformDoughnutConfiguration(labels, values, colors), ChartClass);
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

        const colors = belkhirSpaceChartColors(root);

        renderPlatformDoughnut(
            root.querySelector('[data-platform-chart="tenant-states"]'),
            payload.tenantStates?.labels ?? [],
            payload.tenantStates?.values ?? [],
            [colors.success, colors.warning, colors.muted],
        );
        renderPlatformDoughnut(
            root.querySelector('[data-platform-chart="subscription-states"]'),
            payload.subscriptionStates?.labels ?? [],
            payload.subscriptionStates?.values ?? [],
            [colors.info, colors.success, colors.warning, colors.orange, colors.danger, colors.muted],
        );
        renderPlatformLine(
            root.querySelector('[data-platform-chart="activity"]'),
            payload.activity?.labels ?? [],
            payload.activity?.values ?? [],
            colors.orange,
        );
        renderPlatformBar(
            root.querySelector('[data-platform-chart="activations"]'),
            payload.activations?.labels ?? [],
            payload.activations?.values ?? [],
            colors.blue,
            Chart,
            {
                horizontal: true,
                label: 'Entreprises autorisées',
                maximum: payload.activations?.denominator > 0 ? payload.activations.denominator : null,
            },
        );
    });
}
