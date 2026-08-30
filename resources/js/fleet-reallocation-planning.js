const ACTIVE_STATUSES = new Set(['queued', 'running']);

export function createFleetReallocationPlanning(config = {}) {
    return {
        ready: Boolean(config.ready),
        readinessMessage: safeText(config.readinessMessage, ''),
        referenceDate: validDate(config.referenceDate) ? config.referenceDate : null,
        storeUrl: typeof config.storeUrl === 'string' ? config.storeUrl : '',
        pollDelay: positiveInteger(config.pollDelay) ? Number(config.pollDelay) : 1500,
        maxPollAttempts: positiveInteger(config.maxPollAttempts) ? Number(config.maxPollAttempts) : 120,
        fetchRequest: config.fetchRequest ?? globalThis.fetch.bind(globalThis),
        schedule: config.schedule ?? globalThis.setTimeout.bind(globalThis),
        clearSchedule: config.clearSchedule ?? globalThis.clearTimeout.bind(globalThis),
        status: 'idle',
        outcome: null,
        generatedAt: null,
        agencies: [],
        recommendations: [],
        message: safeText(config.readinessMessage, ''),
        busy: false,
        requestSequence: 0,
        pollTimer: null,
        destroyed: false,

        async calculate() {
            if (! this.ready || this.busy || this.destroyed || this.storeUrl === '') return false;
            this.stopPolling();
            const sequence = ++this.requestSequence;
            this.busy = true;
            this.status = 'queued';
            this.outcome = null;
            this.generatedAt = null;
            this.agencies = [];
            this.recommendations = [];
            this.message = 'Calcul du plan en cours…';
            try {
                const response = await this.fetchRequest(this.storeUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: requestHeaders(true),
                    body: '{}',
                });
                const payload = await response.json();
                if (sequence !== this.requestSequence || this.destroyed) return false;
                if (response.status !== 202 || ! acceptedPayload(payload)) return this.fail(sequence);
                this.status = payload.status;
                this.poll(payload.status_url, sequence, 0);

                return true;
            } catch {
                return this.fail(sequence);
            }
        },

        async poll(url, sequence, attempt) {
            if (this.destroyed || sequence !== this.requestSequence) return;
            if (attempt >= this.maxPollAttempts) return this.fail(sequence);
            try {
                const response = await this.fetchRequest(url, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: requestHeaders(false),
                });
                const payload = await response.json();
                if (this.destroyed || sequence !== this.requestSequence) return;
                if (! response.ok || ! statusPayload(payload)) return this.fail(sequence);

                this.status = payload.status;
                this.message = safeText(payload.message, 'Le plan n’est pas disponible.');
                if (ACTIVE_STATUSES.has(payload.status)) {
                    this.pollTimer = this.schedule(() => this.poll(url, sequence, attempt + 1), this.pollDelay);
                    return;
                }
                if (payload.status === 'failed') return this.fail(sequence, payload.message);

                this.referenceDate = payload.reference_date;
                this.generatedAt = payload.generated_at;
                this.outcome = payload.outcome;
                this.agencies = payload.agencies;
                this.recommendations = payload.recommendations;
                this.busy = false;
            } catch {
                this.fail(sequence);
            }
        },

        fail(sequence, message = 'Le plan n’a pas pu être calculé. Les données métier restent inchangées.') {
            if (sequence !== this.requestSequence || this.destroyed) return false;
            this.stopPolling();
            this.status = 'failed';
            this.outcome = null;
            this.generatedAt = null;
            this.agencies = [];
            this.recommendations = [];
            this.message = safeText(message, 'Le plan n’est pas disponible.');
            this.busy = false;
            return false;
        },

        stopPolling() {
            if (this.pollTimer !== null) {
                this.clearSchedule(this.pollTimer);
                this.pollTimer = null;
            }
        },

        destroy() {
            this.destroyed = true;
            this.requestSequence += 1;
            this.stopPolling();
        },

        formatDate,
        formatGeneratedAt,
    };
}

function acceptedPayload(value) {
    return exactKeys(value, ['run_id', 'status', 'status_url'])
        && typeof value.run_id === 'string'
        && /^[0-9a-f-]{36}$/u.test(value.run_id)
        && ACTIVE_STATUSES.has(value.status)
        && typeof value.status_url === 'string'
        && value.status_url !== '';
}

function statusPayload(value) {
    if (! exactKeys(value, ['status', 'reference_date', 'generated_at', 'outcome', 'agencies', 'recommendations', 'message'])
        || ! ['queued', 'running', 'succeeded', 'failed'].includes(value.status)
        || ! validDate(value.reference_date)
        || ! Array.isArray(value.agencies)
        || ! Array.isArray(value.recommendations)
        || typeof value.message !== 'string') return false;
    if (ACTIVE_STATUSES.has(value.status) || value.status === 'failed') {
        return value.generated_at === null && value.outcome === null && value.recommendations.length === 0;
    }
    return typeof value.generated_at === 'string'
        && ! Number.isNaN(Date.parse(value.generated_at))
        && ['transfers_recommended', 'balanced_without_transfer', 'insufficient_transferable_surplus'].includes(value.outcome)
        && value.agencies.every(validAgency)
        && value.recommendations.every(validRecommendation);
}

function validAgency(value) {
    return exactKeys(value, ['name', 'date', 'available_vehicle_units', 'predicted_departures', 'planning_vehicle_units', 'transferable_surplus', 'uncovered_need'])
        && typeof value.name === 'string'
        && validDate(value.date)
        && /^(?:0|[1-9][0-9]{0,8})\.[0-9]{6}$/u.test(value.predicted_departures)
        && ['available_vehicle_units', 'planning_vehicle_units', 'transferable_surplus', 'uncovered_need']
            .every((key) => Number.isInteger(value[key]) && value[key] >= 0);
}

function validRecommendation(value) {
    return exactKeys(value, ['date', 'from_agency', 'to_agency', 'vehicle_units', 'distance_km'])
        && validDate(value.date)
        && typeof value.from_agency === 'string'
        && typeof value.to_agency === 'string'
        && Number.isInteger(value.vehicle_units)
        && value.vehicle_units > 0
        && /^(?:0|[1-9][0-9]{0,4})\.[0-9]{3}$/u.test(value.distance_km);
}

function requestHeaders(withBody) {
    const csrf = typeof document === 'undefined' ? '' : document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    return {
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrf,
        'X-Requested-With': 'XMLHttpRequest',
        ...(withBody ? { 'Content-Type': 'application/json' } : {}),
    };
}

function exactKeys(value, keys) {
    return value && typeof value === 'object' && ! Array.isArray(value)
        && Object.keys(value).sort().join('|') === [...keys].sort().join('|');
}

function safeText(value, fallback) {
    return typeof value === 'string' && value.trim() !== ''
        ? value.replace(/[\r\n]/gu, ' ').slice(0, 300)
        : fallback;
}

function validDate(value) {
    return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/u.test(value);
}

function positiveInteger(value) {
    return Number.isInteger(Number(value)) && Number(value) > 0;
}

function formatDate(value) {
    if (! validDate(value)) return '';
    return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeZone: 'UTC' })
        .format(new Date(`${value}T12:00:00Z`));
}

function formatGeneratedAt(value) {
    if (typeof value !== 'string' || Number.isNaN(Date.parse(value))) return '';
    return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Africa/Casablanca' })
        .format(new Date(value));
}
