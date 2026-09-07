import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { parseArguments, runSmoke, validateOptions } from '../../scripts/quality/load-smoke.mjs';

async function server(t, handler) {
    const instance = http.createServer(handler);
    await new Promise(resolve => instance.listen(0, '127.0.0.1', resolve));
    t.after(() => new Promise(resolve => { instance.closeAllConnections(); instance.close(resolve); }));
    return `http://127.0.0.1:${instance.address().port}`;
}

test('rejects external, credentialed, non-HTTP and ambiguous targets', () => {
    for (const baseUrl of ['https://payment.cmi.co.ma', 'http://localhost:8000', 'http://example.test',
        'file:///etc/passwd', 'http://127.0.0.1/a', 'http://127.0.0.1?token=x', 'http://user:secret@127.0.0.1']) {
        assert.throws(() => validateOptions({ baseUrl }));
    }
});

test('enforces finite budgets and fixed read-only paths', () => {
    for (const input of [{ requests: 201 }, { requests: 0 }, { requests: NaN }, { concurrency: 9 },
        { timeoutMs: 5001 }, { paths: ['/billing/cmi/callback'] }, { paths: [] }, { paths: ['/login?x=1'] }]) {
        assert.throws(() => validateOptions(input));
    }
});

test('CLI options are strict', () => {
    assert.equal(parseArguments(['--requests', '20']).requests, 20);
    assert.throws(() => parseArguments(['--requests']));
    assert.throws(() => parseArguments(['--requests', '2', '--requests', '3']));
    assert.throws(() => parseArguments(['--token', 'secret']));
});

test('runs exactly the requested count at bounded concurrency', async t => {
    let calls = 0;
    let active = 0;
    let peak = 0;
    const baseUrl = await server(t, (request, response) => {
        calls++;
        active++;
        peak = Math.max(peak, active);
        assert.equal(request.method, 'GET');
        assert.equal(request.headers.cookie, undefined);
        setTimeout(() => { active--; response.end('ok'); }, 10);
    });
    const result = await runSmoke({ baseUrl, requests: 17, concurrency: 3 });
    assert.equal(result.passed, true);
    assert.equal(calls, 17);
    assert.equal(result.completed, 17);
    assert.ok(peak <= 3);
});

test('does not follow redirects', async t => {
    let calls = 0;
    const baseUrl = await server(t, (request, response) => {
        calls++;
        response.writeHead(302, { location: 'https://payment.cmi.co.ma/' }).end();
    });
    const result = await runSmoke({ baseUrl, requests: 2 });
    assert.equal(result.failures, 2);
    assert.equal(calls, 2);
    assert.equal(result.passed, false);
});

test('timeouts and server errors fail the run', async t => {
    const baseUrl = await server(t, () => {});
    const result = await runSmoke({ baseUrl, requests: 2, timeoutMs: 20 });
    assert.equal(result.failures, 2);
    assert.equal(result.passed, false);
});

test('large responses are rejected without storing their bodies', async t => {
    const baseUrl = await server(t, (request, response) => response.end('x'.repeat(1_048_577)));
    assert.equal((await runSmoke({ baseUrl, requests: 1 })).passed, false);
});

test('the latency threshold fails even successful HTTP responses', async t => {
    const baseUrl = await server(t, (request, response) => setTimeout(() => response.end('ok'), 20));
    const result = await runSmoke({ baseUrl, requests: 2, p95Ms: 1 });
    assert.equal(result.failures, 0);
    assert.equal(result.passed, false);
});
