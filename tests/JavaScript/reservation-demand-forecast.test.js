import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import {
    chartConfiguration,
    createReservationDemandForecast,
    createReservationDemandForecastState,
} from '../../resources/js/reservation-demand-forecast.js';

const expectedDates = [
    '2026-08-31',
    '2026-09-01',
    '2026-09-02',
    '2026-09-03',
    '2026-09-04',
    '2026-09-05',
    '2026-09-06',
];

function succeeded(values = [1, 2, 3, 4, 5, 6, 7]) {
    return {
        status: 'succeeded',
        generated_at: '2026-08-30T10:15:00+01:00',
        scope: { agency: 'Agence Centre' },
        forecasts: expectedDates.map((date, index) => ({
            date,
            predicted_demand: values[index].toFixed(6),
        })),
        message: 'Les prévisions sont disponibles pour préparer le planning.',
    };
}

function configuration(overrides = {}) {
    return {
        agencyId: 8,
        agencyName: 'Agence Centre',
        canRequest: true,
        available: true,
        storeUrl: '/reservations/demand-forecast',
        expectedDates,
        initial: succeeded(),
        fetchRequest: async () => { throw new Error('Unexpected request'); },
        schedule: () => 1,
        clearSchedule: () => {},
        chartFactory: () => ({ destroy() {} }),
        ...overrides,
    };
}

function response(status, payload) {
    return {
        status,
        ok: status >= 200 && status < 300,
        async json() { return payload; },
    };
}

test('seven server values drive the accessible table state and chart series identically', () => {
    const state = createReservationDemandForecastState(configuration());
    const chart = chartConfiguration(state.forecasts);

    assert.equal(state.forecasts.length, 7);
    assert.deepEqual(
        chart.data.datasets[0].data,
        state.forecasts.map((forecast) => forecast.predictedDemand),
    );
    assert.deepEqual(state.forecasts.map((forecast) => forecast.date), expectedDates);
    assert.deepEqual(state.forecasts.map((forecast) => forecast.predictedDemand), [1, 2, 3, 4, 5, 6, 7]);
});

test('chart is responsive, starts at zero and uses the seven daily labels', () => {
    const state = createReservationDemandForecastState(configuration());
    const chart = chartConfiguration(state.forecasts);

    assert.equal(chart.type, 'line');
    assert.equal(chart.options.responsive, true);
    assert.equal(chart.options.maintainAspectRatio, false);
    assert.equal(chart.options.scales.y.beginAtZero, true);
    assert.equal(chart.data.labels.length, 7);
});

test('rendering replacement destroys the preceding chart instance', () => {
    let destroyed = 0;
    let created = 0;
    const state = createReservationDemandForecast(configuration({
        chartFactory: () => {
            created += 1;

            return { destroy() { destroyed += 1; } };
        },
    }));

    state.renderChart({});
    state.renderChart({});
    state.destroy();

    assert.equal(created, 2);
    assert.equal(destroyed, 2);
    assert.equal(state.chart, null);
});

test('a double click sends only one request while polling is active', async () => {
    let posts = 0;
    const state = createReservationDemandForecast(configuration({
        initial: { status: 'empty', message: 'Aucune prévision.', scope: { agency: 'Agence Centre' } },
        fetchRequest: async (_url, options) => {
            if (options.method === 'POST') {
                posts += 1;

                return response(202, {
                    run_id: '123e4567-e89b-12d3-a456-426614174000',
                    status: 'queued',
                    status_url: '/reservations/demand-forecast/123e4567-e89b-12d3-a456-426614174000',
                });
            }

            return response(200, {
                status: 'queued',
                generated_at: null,
                scope: { agency: 'Agence Centre' },
                forecasts: [],
                message: 'Préparation des prévisions en cours…',
            });
        },
    }));

    await Promise.all([state.refresh(), state.refresh()]);

    assert.equal(posts, 1);
    assert.equal(state.busy, true);
});

test('polling stops on success and applies exactly seven values', async () => {
    const requests = [];
    const state = createReservationDemandForecast(configuration({
        initial: { status: 'empty', message: 'Aucune prévision.', scope: { agency: 'Agence Centre' } },
        fetchRequest: async (url, options) => {
            requests.push([url, options.method]);
            if (options.method === 'POST') {
                return response(202, {
                    run_id: '123e4567-e89b-12d3-a456-426614174000',
                    status: 'queued',
                    status_url: '/status/1',
                });
            }

            return response(200, succeeded([2, 4, 6, 8, 10, 12, 14]));
        },
    }));

    await state.refresh();
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(state.status, 'succeeded');
    assert.equal(state.busy, false);
    assert.deepEqual(state.forecasts.map((row) => row.predictedDemand), [2, 4, 6, 8, 10, 12, 14]);
    assert.equal(requests.length, 2);
});

test('failed terminal status stops polling without exposing server details', async () => {
    const state = createReservationDemandForecast(configuration({
        initial: { status: 'empty', message: 'Aucune prévision.', scope: { agency: 'Agence Centre' } },
        fetchRequest: async (_url, options) => options.method === 'POST'
            ? response(202, {
                run_id: '123e4567-e89b-12d3-a456-426614174000',
                status: 'queued',
                status_url: '/status/1',
            })
            : response(200, {
                status: 'failed',
                generated_at: null,
                scope: { agency: 'Agence Centre' },
                forecasts: [],
                message: 'Une exception runtime a été levée.',
            }),
    }));

    await state.refresh();
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(state.status, 'failed');
    assert.equal(state.message, 'Les prévisions ne sont pas disponibles. Le planning reste utilisable.');
    assert.equal(state.busy, false);
});

test('polling timeout ends in a safe manual planning state', async () => {
    const scheduled = [];
    const state = createReservationDemandForecast(configuration({
        initial: { status: 'empty', message: 'Aucune prévision.', scope: { agency: 'Agence Centre' } },
        maxPollAttempts: 1,
        schedule: (callback) => {
            scheduled.push(callback);

            return scheduled.length;
        },
        fetchRequest: async (_url, options) => options.method === 'POST'
            ? response(202, {
                run_id: '123e4567-e89b-12d3-a456-426614174000',
                status: 'queued',
                status_url: '/status/1',
            })
            : response(200, {
                status: 'running',
                generated_at: null,
                scope: { agency: 'Agence Centre' },
                forecasts: [],
                message: 'Préparation des prévisions en cours…',
            }),
    }));

    await state.refresh();
    await new Promise((resolve) => setImmediate(resolve));
    await scheduled[0]();

    assert.equal(state.status, 'failed');
    assert.equal(state.busy, false);
});

test('destroy invalidates a late response and clears pending work', async () => {
    let resolvePost;
    const post = new Promise((resolve) => { resolvePost = resolve; });
    const state = createReservationDemandForecast(configuration({
        initial: { status: 'empty', message: 'Aucune prévision.', scope: { agency: 'Agence Centre' } },
        fetchRequest: async () => post,
    }));

    const refresh = state.refresh();
    state.destroy();
    resolvePost(response(202, {
        run_id: '123e4567-e89b-12d3-a456-426614174000',
        status: 'queued',
        status_url: '/status/1',
    }));
    await refresh;

    assert.equal(state.destroyed, true);
    assert.equal(state.runId, '');
    assert.equal(state.forecasts.length, 0);
});

test('invalid dates, negative values and extra response keys are rejected', () => {
    const invalidDate = succeeded();
    invalidDate.forecasts[6].date = '2026-09-08';
    const negative = succeeded();
    negative.forecasts[2].predicted_demand = '-1.000000';
    const leaked = succeeded();
    leaked.model_path = 'private';

    for (const initial of [invalidDate, negative, leaked]) {
        const state = createReservationDemandForecastState(configuration({ initial }));
        assert.equal(state.status, 'empty');
        assert.deepEqual(state.forecasts, []);
    }
});

test('Blade keeps an aria-labelled canvas and the accessible fallback table', () => {
    const blade = readFileSync('resources/views/reservations/index.blade.php', 'utf8');

    assert.match(blade, /<canvas[\s\S]*role="img"[\s\S]*aria-label=/u);
    assert.match(blade, /<caption class="sr-only">/u);
    assert.match(blade, /Demande prévue/u);
    assert.match(blade, /Actualiser les prévisions/u);
    assert.doesNotMatch(blade, /\b(?:HistGradientBoosting|joblib|bundle|SHA-256|runtime|worker|feature vector|traceback)\b/iu);
});
