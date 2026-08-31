import assert from 'node:assert/strict';
import test from 'node:test';

import { belkhirSpaceChartColors } from '../../resources/js/belkhir-space-chart-theme.js';
import {
    platformChartConfiguration,
    platformChartsPreferReducedMotion,
    platformDoughnutConfiguration,
    platformLineConfiguration,
    renderPlatformBar,
} from '../../resources/js/platform-statistics.js';

test('platform charts use the BELKHIR SPACE palette without changing source values', () => {
    const colors = belkhirSpaceChartColors();
    const labels = ['Actives', 'Suspendues'];
    const values = [12, 3];
    const configuration = platformChartConfiguration(labels, values, colors.blue);

    assert.deepEqual(configuration.data.labels, labels);
    assert.deepEqual(configuration.data.datasets[0].data, values);
    assert.equal(configuration.data.datasets[0].backgroundColor, '#1D4ED8');
    assert.equal(configuration.options.responsive, true);
    assert.equal(configuration.options.maintainAspectRatio, false);
    assert.equal(configuration.options.scales.y.beginAtZero, true);
    assert.equal(configuration.options.scales.y.ticks.callback(19263), '19 263');
    assert.equal(configuration.options.plugins.tooltip.callbacks.label({
        label: 'Actives',
        raw: 19263,
        dataset: { label: 'Nombre' },
    }), 'Actives: 19 263');
    assert.equal(configuration.options.animation.duration, 500);
});

test('platform charts expose distinct accessible visual configurations without changing values', () => {
    const labels = ['Actives', 'Suspendues'];
    const values = [12, 3];
    const colors = belkhirSpaceChartColors();
    const doughnut = platformDoughnutConfiguration(labels, values, [colors.success, colors.warning]);
    const line = platformLineConfiguration(labels, values, colors.orange);
    const horizontal = platformChartConfiguration(labels, values, colors.blue, {
        horizontal: true,
        maximum: 15,
    });

    assert.deepEqual(doughnut.data.datasets[0].data, values);
    assert.equal(doughnut.type, 'doughnut');
    assert.equal(doughnut.options.cutout, '68%');
    assert.deepEqual(line.data.datasets[0].data, values);
    assert.equal(line.type, 'line');
    assert.equal(horizontal.options.indexAxis, 'y');
    assert.equal(horizontal.options.scales.x.max, 15);
});

test('platform chart animation respects reduced motion', () => {
    globalThis.window = {
        matchMedia: (query) => ({ matches: query === '(prefers-reduced-motion: reduce)' }),
    };

    assert.equal(platformChartsPreferReducedMotion(), true);
    assert.equal(platformChartConfiguration(['Actives'], [1], '#1D4ED8').options.animation, false);

    delete globalThis.window;
});

test('rendering a platform chart destroys the previous canvas instance', () => {
    let removedClass = null;
    let shellReady = null;
    let shellBusy = null;
    const skeletonAttributes = new Map();
    const skeleton = {
        hidden: false,
        setAttribute: (name, value) => skeletonAttributes.set(name, value),
    };
    const shell = {
        querySelector: () => skeleton,
        setAttribute: (name, value) => {
            if (name === 'data-chart-ready') shellReady = value;
            if (name === 'aria-busy') shellBusy = value;
        },
    };
    globalThis.HTMLCanvasElement = class HTMLCanvasElement {
        classList = { remove: (name) => { removedClass = name; } };

        closest() {
            return shell;
        }
    };
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
    assert.equal(removedClass, 'opacity-0');
    assert.equal(skeleton.hidden, true);
    assert.equal(skeletonAttributes.get('aria-hidden'), 'true');
    assert.equal(shellReady, 'true');
    assert.equal(shellBusy, 'false');
    delete globalThis.HTMLCanvasElement;
});
