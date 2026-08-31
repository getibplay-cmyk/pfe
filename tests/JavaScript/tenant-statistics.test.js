import assert from 'node:assert/strict';
import test from 'node:test';

import { initializeTenantStatistics, parseTenantStatisticsPayload } from '../../resources/js/tenant-statistics.js';

test('tenant statistics parse real integer series without changing values', () => {
    const payload = {
        period: { from: '2026-08-01', to: '2026-08-31' },
        charts: {
            reservations: { labels: ['Créées', 'Confirmées'], values: [9, 7], total: 16 },
            contracts: { labels: ['Actifs'], values: [5], total: 5 },
            fleet: { labels: ['Disponibles'], values: [6], total: 6 },
        },
    };
    const source = { textContent: JSON.stringify(payload) };

    assert.deepEqual(parseTenantStatisticsPayload(source), payload);
    assert.deepEqual(payload.charts.reservations.values, [9, 7]);
});

test('tenant statistics reject malformed payloads and tolerate absent canvases', () => {
    assert.equal(parseTenantStatisticsPayload({ textContent: '{invalid' }), null);
    assert.equal(parseTenantStatisticsPayload(null), null);

    const root = {
        querySelector: (selector) => selector.includes('payload') ? { textContent: '{}' } : null,
    };
    const documentStub = { querySelectorAll: () => [root] };

    assert.doesNotThrow(() => initializeTenantStatistics(documentStub));
});
