import { performance } from 'node:perf_hooks';
import { pathToFileURL } from 'node:url';

const allowedPaths = new Set(['/health', '/login', '/tarifs', '/']);

function integer(value, name, min, max) {
    if (!Number.isInteger(value) || value < min || value > max) {
        throw new Error(`${name} must be an integer between ${min} and ${max}`);
    }
    return value;
}

export function validateOptions(input = {}) {
    const url = new URL(input.baseUrl ?? 'http://127.0.0.1:8000');
    if (url.protocol !== 'http:' || !['127.0.0.1', '[::1]'].includes(url.hostname)
        || url.username || url.password || url.pathname !== '/' || url.search || url.hash) {
        throw new Error('Only a numeric loopback HTTP origin is allowed; no credentials, paths or external targets');
    }
    const paths = input.paths ?? ['/health', '/login', '/tarifs'];
    if (!Array.isArray(paths) || paths.length < 1 || paths.length > 4 || paths.some(path => !allowedPaths.has(path))) {
        throw new Error('Only the fixed public read-only routes are allowed');
    }
    return {
        baseUrl: url.origin,
        paths: [...paths],
        requests: integer(input.requests ?? 60, 'requests', 1, 200),
        concurrency: integer(input.concurrency ?? 4, 'concurrency', 1, 8),
        timeoutMs: integer(input.timeoutMs ?? 3000, 'timeoutMs', 10, 5000),
        p95Ms: integer(input.p95Ms ?? 2000, 'p95Ms', 1, 10000),
    };
}

export function parseArguments(args) {
    const names = new Map([
        ['--base-url', 'baseUrl'], ['--requests', 'requests'], ['--concurrency', 'concurrency'],
        ['--timeout-ms', 'timeoutMs'], ['--p95-ms', 'p95Ms'],
    ]);
    const input = {};
    for (let index = 0; index < args.length; index += 2) {
        const name = names.get(args[index]);
        if (!name || args[index + 1] === undefined || name in input) throw new Error('Unknown, duplicate or incomplete option');
        input[name] = name === 'baseUrl' ? args[index + 1] : Number(args[index + 1]);
    }
    return validateOptions(input);
}

export async function runSmoke(input = {}) {
    const options = validateOptions(input);
    let next = 0;
    const samples = [];
    const started = performance.now();
    const deadline = AbortSignal.timeout(120000);
    async function worker() {
        while (next < options.requests && !deadline.aborted) {
            const index = next++;
            const path = options.paths[index % options.paths.length];
            const before = performance.now();
            let ok = false;
            let status = 0;
            try {
                const response = await fetch(`${options.baseUrl}${path}`, {
                    method: 'GET', redirect: 'manual', credentials: 'omit',
                    signal: AbortSignal.any([deadline, AbortSignal.timeout(options.timeoutMs)]),
                    headers: { 'User-Agent': 'BELKHIR-SPACE-local-smoke/1.0' },
                });
                status = response.status;
                let bytes = 0;
                if (response.body) {
                    for await (const chunk of response.body) {
                        bytes += chunk.byteLength;
                        if (bytes > 1_048_576) throw new Error('Response exceeds 1 MiB');
                    }
                }
                ok = status === 200;
            } catch {
                // Never log response bodies, cookies, query strings or credentials.
            }
            samples.push({ path, status, ok, ms: performance.now() - before });
        }
    }
    await Promise.all(Array.from({ length: Math.min(options.concurrency, options.requests) }, worker));
    const durations = samples.map(sample => sample.ms).sort((a, b) => a - b);
    const p95 = durations[Math.max(0, Math.ceil(durations.length * 0.95) - 1)] ?? 0;
    const failures = samples.filter(sample => !sample.ok).length;
    return {
        target: options.baseUrl, requested: options.requests, completed: samples.length,
        concurrency: options.concurrency, failures, p95Ms: Math.round(p95),
        durationMs: Math.round(performance.now() - started),
        passed: failures === 0 && samples.length === options.requests && p95 <= options.p95Ms,
        routes: options.paths.map(path => ({ path, requests: samples.filter(sample => sample.path === path).length })),
        note: 'Bounded local smoke only; not a production capacity benchmark.',
    };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    try {
        const result = await runSmoke(parseArguments(process.argv.slice(2)));
        console.log(JSON.stringify(result, null, 2));
        process.exitCode = result.passed ? 0 : 1;
    } catch (error) {
        console.error(error.message);
        process.exitCode = 2;
    }
}
