import {
    CategoryScale,
    Chart,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
} from 'chart.js';
import { atlasChartColors, atlasCartesianScales } from './atlas-chart-theme.js';

Chart.register(
    CategoryScale,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
    Legend,
);

const ACTIVE_STATUSES = new Set(['queued', 'running']);
const PROCESSING_MESSAGE = 'Préparation des prévisions en cours…';
const UNAVAILABLE_MESSAGE = 'Les prévisions ne sont pas disponibles. Le planning reste utilisable.';
const FORBIDDEN_CLIENT_TERMS = /\b(?:hgb|histgradientboosting|joblib|bundle|sha(?:-?256)?|runtime|worker|feature vector|exception|trace(?:back)?)\b/iu;

export function createReservationDemandForecastState(config = {}) {
    const expectedDates = validExpectedDates(config.expectedDates) ? [...config.expectedDates] : [];
    const state = {
        agencyId: positiveInteger(config.agencyId) ? Number(config.agencyId) : null,
        agencyName: safeText(config.agencyName, ''),
        canRequest: Boolean(config.canRequest),
        available: Boolean(config.available),
        storeUrl: typeof config.storeUrl === 'string' ? config.storeUrl : '',
        expectedDates,
        status: 'empty',
        generatedAt: null,
        scope: { agency: safeText(config.agencyName, '') },
        forecasts: [],
        message: '',
        busy: false,
        runId: '',
        requestSequence: 0,
        pollTimer: null,
        chart: null,
        destroyed: false,
    };

    const initial = config.initial;
    if (initial?.status === 'succeeded' && validStatusPayload(initial, expectedDates)) {
        applySucceeded(state, initial);
    } else {
        state.status = 'empty';
        state.message = safeText(initial?.message, 'Aucune prévision récente n’est disponible.');
        state.scope = {
            agency: safeText(initial?.scope?.agency, state.agencyName),
        };
    }

    return state;
}

export function createReservationDemandForecast(config = {}) {
    const state = createReservationDemandForecastState(config);
    state.pollDelay = positiveInteger(config.pollDelay) ? Number(config.pollDelay) : 1500;
    state.maxPollAttempts = positiveInteger(config.maxPollAttempts)
        ? Number(config.maxPollAttempts)
        : 120;
    state.fetchRequest = config.fetchRequest ?? window.fetch.bind(window);
    state.schedule = config.schedule ?? window.setTimeout.bind(window);
    state.clearSchedule = config.clearSchedule ?? window.clearTimeout.bind(window);
    state.chartFactory = config.chartFactory ?? ((canvas, chartConfig) => new Chart(canvas, chartConfig));

    state.init = function () {
        this.$nextTick?.(() => this.renderChart());
    };

    state.refresh = async function () {
        if (this.destroyed
            || this.busy
            || ! this.canRequest
            || ! this.available
            || ! positiveInteger(this.agencyId)
            || this.storeUrl === '') {
            return false;
        }

        this.stopPolling();
        this.requestSequence += 1;
        const sequence = this.requestSequence;
        this.busy = true;
        this.status = 'queued';
        this.generatedAt = null;
        this.forecasts = [];
        this.message = PROCESSING_MESSAGE;
        this.runId = '';
        this.renderChart();

        try {
            const response = await this.fetchRequest(this.storeUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: requestHeaders(true),
                body: JSON.stringify({ agency_id: Number(this.agencyId) }),
            });
            const payload = await response.json();
            if (sequence !== this.requestSequence || this.destroyed) return false;
            if (response.status !== 202 || ! validAcceptedPayload(payload)) {
                return this.fail(sequence, payload?.message);
            }

            this.runId = payload.run_id;
            this.status = payload.status;
            this.poll(payload.status_url, sequence, 0);

            return true;
        } catch {
            return this.fail(sequence);
        }
    };

    state.poll = async function (statusUrl, sequence, attempt) {
        if (this.destroyed || sequence !== this.requestSequence) return;
        if (attempt >= this.maxPollAttempts) {
            this.fail(sequence);

            return;
        }

        try {
            const response = await this.fetchRequest(statusUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: requestHeaders(false),
            });
            const payload = await response.json();
            if (this.destroyed || sequence !== this.requestSequence) return;
            if (! response.ok || ! validStatusPayload(payload, this.expectedDates)) {
                this.fail(sequence, payload?.message);

                return;
            }

            this.status = payload.status;
            this.message = safeText(payload.message, UNAVAILABLE_MESSAGE);
            this.scope = { agency: safeText(payload.scope.agency, this.agencyName) };
            if (ACTIVE_STATUSES.has(payload.status)) {
                this.pollTimer = this.schedule(
                    () => this.poll(statusUrl, sequence, attempt + 1),
                    this.pollDelay,
                );

                return;
            }
            if (payload.status === 'failed') {
                this.fail(sequence, payload.message);

                return;
            }

            applySucceeded(this, payload);
            this.busy = false;
            this.runId = '';
            this.queueChartRender();
        } catch {
            this.fail(sequence);
        }
    };

    state.fail = function (sequence, message = UNAVAILABLE_MESSAGE) {
        if (sequence !== this.requestSequence || this.destroyed) return false;

        this.stopPolling();
        this.status = 'failed';
        this.generatedAt = null;
        this.forecasts = [];
        this.message = safeText(message, UNAVAILABLE_MESSAGE);
        this.busy = false;
        this.runId = '';
        this.renderChart();

        return false;
    };

    state.stopPolling = function () {
        if (this.pollTimer !== null) {
            this.clearSchedule(this.pollTimer);
            this.pollTimer = null;
        }
    };

    state.renderChart = function (canvas = this.$refs?.forecastChart) {
        if (this.chart !== null) {
            this.chart.destroy();
            this.chart = null;
        }
        if (this.destroyed || this.forecasts.length !== 7 || ! canvas) return;

        this.chart = this.chartFactory(canvas, chartConfiguration(this.forecasts));
    };

    state.queueChartRender = function () {
        if (typeof this.$nextTick === 'function') {
            this.$nextTick(() => this.renderChart());
        } else {
            this.renderChart();
        }
    };

    state.destroy = function () {
        this.destroyed = true;
        this.requestSequence += 1;
        this.stopPolling();
        if (this.chart !== null) {
            this.chart.destroy();
            this.chart = null;
        }
    };

    state.formatDate = formatDate;
    state.formatDemand = formatDemand;
    state.formatGeneratedAt = formatGeneratedAt;

    return state;
}

export function chartConfiguration(forecasts) {
    const colors = atlasChartColors();
    const scales = atlasCartesianScales();

    return {
        type: 'line',
        data: {
            labels: forecasts.map((forecast) => formatDate(forecast.date)),
            datasets: [{
                label: 'Demande prévue',
                data: forecasts.map((forecast) => forecast.predictedDemand),
                borderColor: colors.blue,
                backgroundColor: colors.blue,
                pointBackgroundColor: colors.orange,
                pointBorderColor: colors.surface,
                pointBorderWidth: 2,
                pointRadius: 4,
                tension: 0.3,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            interaction: { intersect: false, mode: 'index' },
            scales: {
                y: {
                    ...scales.y,
                    title: { display: true, text: 'Départs prévus' },
                },
                x: {
                    ...scales.x,
                    title: { display: true, text: 'Date' },
                },
            },
            plugins: {
                legend: { display: true, labels: { color: colors.muted } },
            },
        },
    };
}

function applySucceeded(state, payload) {
    state.status = 'succeeded';
    state.generatedAt = payload.generated_at;
    state.scope = { agency: safeText(payload.scope.agency, state.agencyName) };
    state.forecasts = payload.forecasts.map((forecast) => ({
        date: forecast.date,
        predictedDemand: Number(forecast.predicted_demand),
    }));
    state.message = safeText(payload.message, 'Les prévisions sont disponibles pour préparer le planning.');
}

function validAcceptedPayload(payload) {
    return exactKeys(payload, ['run_id', 'status', 'status_url'])
        && typeof payload.run_id === 'string'
        && /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iu.test(payload.run_id)
        && ACTIVE_STATUSES.has(payload.status)
        && typeof payload.status_url === 'string'
        && payload.status_url !== '';
}

function validStatusPayload(payload, expectedDates) {
    if (! exactKeys(payload, ['forecasts', 'generated_at', 'message', 'scope', 'status'])
        || ! ['queued', 'running', 'succeeded', 'failed'].includes(payload.status)
        || ! exactKeys(payload.scope, ['agency'])
        || typeof payload.scope.agency !== 'string'
        || typeof payload.message !== 'string'
        || FORBIDDEN_CLIENT_TERMS.test(payload.message)
        || ! Array.isArray(payload.forecasts)) {
        return false;
    }
    if (ACTIVE_STATUSES.has(payload.status) || payload.status === 'failed') {
        return payload.generated_at === null && payload.forecasts.length === 0;
    }
    if (payload.generated_at === null
        || Number.isNaN(Date.parse(payload.generated_at))
        || payload.forecasts.length !== 7
        || ! validExpectedDates(expectedDates)) {
        return false;
    }

    return payload.forecasts.every((forecast, position) => {
        if (! exactKeys(forecast, ['date', 'predicted_demand'])
            || forecast.date !== expectedDates[position]) {
            return false;
        }
        if (typeof forecast.predicted_demand !== 'string'
            || ! /^(?:0|[1-9][0-9]{0,7})\.[0-9]{6}$/u.test(forecast.predicted_demand)) {
            return false;
        }
        const numeric = Number(forecast.predicted_demand);

        return Number.isFinite(numeric) && numeric >= 0;
    });
}

function validExpectedDates(value) {
    return Array.isArray(value)
        && value.length === 7
        && value.every((date, position) => typeof date === 'string'
            && /^\d{4}-\d{2}-\d{2}$/u.test(date)
            && (position === 0 || nextDate(value[position - 1]) === date));
}

function nextDate(value) {
    const date = new Date(`${value}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + 1);

    return date.toISOString().slice(0, 10);
}

function exactKeys(value, expected) {
    if (! value || typeof value !== 'object' || Array.isArray(value)) return false;

    return Object.keys(value).sort().join('|') === [...expected].sort().join('|');
}

function positiveInteger(value) {
    return Number.isInteger(Number(value)) && Number(value) > 0;
}

function requestHeaders(withBody) {
    const csrf = typeof document === 'undefined'
        ? ''
        : document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    const headers = {
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrf,
        'X-Requested-With': 'XMLHttpRequest',
    };
    if (withBody) headers['Content-Type'] = 'application/json';

    return headers;
}

function safeText(value, fallback) {
    return typeof value === 'string'
        && value.trim() !== ''
        && ! FORBIDDEN_CLIENT_TERMS.test(value)
        ? value.replace(/[\r\n]/gu, ' ').slice(0, 300)
        : fallback;
}

function formatDate(value) {
    if (typeof value !== 'string' || ! /^\d{4}-\d{2}-\d{2}$/u.test(value)) return '';

    return new Intl.DateTimeFormat('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(`${value}T12:00:00Z`));
}

function formatDemand(value) {
    const numeric = Number(value);
    if (! Number.isFinite(numeric) || numeric < 0) return '';

    return new Intl.NumberFormat('fr-FR', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    }).format(numeric);
}

function formatGeneratedAt(value) {
    if (typeof value !== 'string' || Number.isNaN(Date.parse(value))) return '';

    return new Intl.DateTimeFormat('fr-FR', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Africa/Casablanca',
    }).format(new Date(value));
}
