import assert from 'node:assert/strict';
import test from 'node:test';

import { atlasChartColors } from '../../resources/js/atlas-chart-theme.js';
import { platformChartConfiguration, renderPlatformBar } from '../../resources/js/platform-statistics.js';

test('platform charts use the Atlas palette without changing source values', () => {
    const colors = atlasChartColors();
    const labels = ['Actives', 'Suspendues'];
    const values = [12, 3];
    const configuration = platformChartConfiguration(labels, values, colors.blue);

    assert.deepEqual(configuration.data.labels, labels);
    assert.deepEqual(configuration.data.datasets[0].data, values);
    assert.equal(configuration.data.datasets[0].backgroundColor, '#1D4ED8');
    assert.equal(configuration.options.responsive, true);
    assert.equal(configuration.options.maintainAspectRatio, false);
    assert.equal(configuration.options.scales.y.beginAtZero, true);
});

test('rendering a platform chart destroys the previous canvas instance', () => {
    globalThis.HTMLCanvasElement = class HTMLCanvasElement {};
    const canvas = new globalThis.HTMLCanvasElement();
    let destroyed = 0;
    let receivedConfiguration = null;

    class FakeChart {
        static getChart(receivedCanvas) {
            assert.equal(receivedCanvas, canvas);

            return { destroy: () => { destroyed += 1; } };
        }

        constructor(receivedCanvas, configuration) {
            assert.equal(receivedCanvas, canvas);
            receivedConfiguration = configuration;
        }
    }

    const chart = renderPlatformBar(canvas, ['Août'], [7], '#C2410C', FakeChart);

    assert.ok(chart instanceof FakeChart);
    assert.equal(destroyed, 1);
    assert.deepEqual(receivedConfiguration.data.datasets[0].data, [7]);
    delete globalThis.HTMLCanvasElement;
});
