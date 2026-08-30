import assert from 'node:assert/strict';
import test from 'node:test';
import { createFleetReallocationPlanning } from '../../resources/js/fleet-reallocation-planning.js';

const accepted = {
    run_id: '123e4567-e89b-42d3-a456-426614174000',
    status: 'queued',
    status_url: '/fleet/reallocation-planning/runs/123/status',
};

function response(status, payload) {
    return { status, ok: status >= 200 && status < 300, json: async () => payload };
}

test('prevents a double click and sends no planning input', async () => {
    const calls = [];
    let resolve;
    const pending = new Promise((done) => { resolve = done; });
    const state = createFleetReallocationPlanning({
        ready: true,
        storeUrl: '/fleet/reallocation-planning/runs',
        fetchRequest: async (url, options) => {
            calls.push({ url, options });
            await pending;
            return response(202, accepted);
        },
        schedule: () => 1,
    });
    const first = state.calculate();
    assert.equal(await state.calculate(), false);
    resolve();
    assert.equal(await first, true);
    const posts = calls.filter((call) => call.options.method === 'POST');
    assert.equal(posts.length, 1);
    assert.equal(posts[0].options.body, '{}');
});

test('ignores stale responses and cancels polling when destroyed', async () => {
    let scheduled;
    let cleared = null;
    let call = 0;
    const state = createFleetReallocationPlanning({
        ready: true,
        storeUrl: '/fleet/reallocation-planning/runs',
        fetchRequest: async () => (++call === 1 ? response(202, accepted) : response(200, {
            status: 'queued',
            reference_date: '2026-08-30',
            generated_at: null,
            outcome: null,
            agencies: [],
            recommendations: [],
            message: 'Calcul en cours…',
        })),
        schedule: (callback) => { scheduled = callback; return 73; },
        clearSchedule: (timer) => { cleared = timer; },
    });
    await state.calculate();
    await new Promise((resolve) => setTimeout(resolve, 0));
    assert.equal(typeof scheduled, 'function');
    state.destroy();
    assert.equal(cleared, 73);
    assert.equal(state.destroyed, true);
});

test('accepts the bounded successful business contract', async () => {
    const status = {
        status: 'succeeded',
        reference_date: '2026-08-30',
        generated_at: '2026-08-30T12:00:00Z',
        outcome: 'transfers_recommended',
        agencies: [{
            name: 'Casablanca Centre',
            date: '2026-08-31',
            available_vehicle_units: 8,
            predicted_departures: '3.250000',
            planning_vehicle_units: 4,
            transferable_surplus: 4,
            uncovered_need: 0,
        }],
        recommendations: [{
            date: '2026-08-31',
            from_agency: 'Casablanca Centre',
            to_agency: 'Rabat Agdal',
            vehicle_units: 2,
            distance_km: '87.400',
        }],
        message: 'Des transferts sont proposés.',
    };
    const calls = [];
    const state = createFleetReallocationPlanning({
        ready: true,
        storeUrl: '/fleet/reallocation-planning/runs',
        fetchRequest: async (...args) => {
            calls.push(args);
            return calls.length === 1 ? response(202, accepted) : response(200, status);
        },
        schedule: (callback) => { queueMicrotask(callback); return 1; },
    });
    await state.calculate();
    await new Promise((resolve) => setTimeout(resolve, 0));
    assert.equal(state.status, 'succeeded');
    assert.equal(state.recommendations.length, 1);
    assert.equal(state.busy, false);
});
