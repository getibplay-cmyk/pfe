import { belkhirSpaceChartColors } from './belkhir-space-chart-theme.js';
import { renderPlatformBar, renderPlatformDoughnut } from './platform-statistics.js';

export function parseTenantStatisticsPayload(source) {
    if (! source) return null;

    try {
        const payload = JSON.parse(source.textContent ?? '{}');

        return payload && typeof payload === 'object' ? payload : null;
    } catch {
        return null;
    }
}

export function initializeTenantStatistics(rootDocument = document) {
    rootDocument.querySelectorAll('[data-tenant-statistics]').forEach((root) => {
        const payload = parseTenantStatisticsPayload(root.querySelector('[data-tenant-statistics-payload]'));
        if (! payload) return;

        const colors = belkhirSpaceChartColors(root);

        renderPlatformBar(
            root.querySelector('[data-tenant-chart="reservations"]'),
            payload.charts?.reservations?.labels ?? [],
            payload.charts?.reservations?.values ?? [],
            colors.blue,
            undefined,
            { label: 'Réservations' },
        );
        renderPlatformBar(
            root.querySelector('[data-tenant-chart="contracts"]'),
            payload.charts?.contracts?.labels ?? [],
            payload.charts?.contracts?.values ?? [],
            colors.orange,
            undefined,
            { label: 'Contrats' },
        );
        renderPlatformDoughnut(
            root.querySelector('[data-tenant-chart="fleet"]'),
            payload.charts?.fleet?.labels ?? [],
            payload.charts?.fleet?.values ?? [],
            [colors.success, colors.info, colors.warning, colors.orange, colors.muted],
        );
    });
}
