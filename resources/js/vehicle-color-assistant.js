import { formatConfidence } from './business-number.js';

const ACTIVE_STATUSES = new Set(['queued', 'running']);
const SUPPORTED_COLORS = new Set([
    'black',
    'blue',
    'gray',
    'green',
    'orange',
    'red',
    'white',
    'yellow',
]);
const MANUAL_MESSAGE = 'La couleur n’a pas pu être déterminée. Sélectionnez-la manuellement.';

export function createVehicleColorAssistantState(initialColor = '') {
    return {
        phase: 'idle',
        colorValue: String(initialColor ?? ''),
        colorWasEdited: false,
        suggestion: null,
        confidence: null,
        message: '',
        acceptedRunId: '',
        showUseSuggestion: false,
        requestSequence: 0,

        get busy() {
            return this.phase === 'uploading' || this.phase === 'processing';
        },

        beginAnalysis() {
            this.requestSequence += 1;
            this.phase = 'uploading';
            this.suggestion = null;
            this.confidence = null;
            this.message = 'Analyse de la photo en cours…';
            this.acceptedRunId = '';
            this.showUseSuggestion = false;

            return this.requestSequence;
        },

        markColorEdited(value) {
            this.colorValue = String(value ?? '');
            this.colorWasEdited = true;
            this.showUseSuggestion = this.suggestion !== null
                && this.colorValue !== this.suggestion.label;
        },

        acceptStatus(sequence, runId, payload) {
            if (sequence !== this.requestSequence || ! payload || typeof payload.status !== 'string') {
                return false;
            }

            if (ACTIVE_STATUSES.has(payload.status)) {
                this.phase = 'processing';
                this.message = safeMessage(payload.message, 'Analyse de la photo en cours…');

                return true;
            }

            if (payload.status === 'failed') {
                return this.fail(sequence);
            }

            const candidate = payload.suggested_color;
            const confidence = Number(payload.confidence);
            if (payload.status !== 'succeeded'
                || ! candidate
                || typeof candidate.value !== 'string'
                || typeof candidate.label !== 'string'
                || ! SUPPORTED_COLORS.has(candidate.value)
                || candidate.label.trim() === ''
                || ! Number.isFinite(confidence)
                || confidence < 0
                || confidence > 1) {
                return this.fail(sequence);
            }

            this.phase = 'succeeded';
            this.suggestion = {
                value: candidate.value,
                label: candidate.label,
            };
            this.confidence = confidence;
            this.message = safeMessage(
                payload.message,
                'Vous pouvez modifier cette couleur avant l’enregistrement.',
            );
            this.acceptedRunId = String(runId);
            if (this.colorWasEdited) {
                this.showUseSuggestion = this.colorValue !== this.suggestion.label;
            } else {
                this.colorValue = this.suggestion.label;
                this.showUseSuggestion = false;
            }

            return true;
        },

        fail(sequence, message = MANUAL_MESSAGE) {
            if (sequence !== this.requestSequence) {
                return false;
            }

            this.phase = 'failed';
            this.suggestion = null;
            this.confidence = null;
            this.message = safeMessage(message, MANUAL_MESSAGE);
            this.acceptedRunId = '';
            this.showUseSuggestion = false;

            return true;
        },

        useSuggestion() {
            if (this.suggestion === null) {
                return false;
            }

            this.colorValue = this.suggestion.label;
            this.colorWasEdited = true;
            this.showUseSuggestion = false;

            return true;
        },

        confidenceText() {
            if (! Number.isFinite(this.confidence)) {
                return '';
            }

            return formatConfidence(this.confidence);
        },
    };
}

export function createVehicleColorAssistant(config = {}) {
    const state = createVehicleColorAssistantState(config.initialColor ?? '');
    const urlApi = config.urlApi ?? globalThis.window?.URL ?? globalThis.URL;
    state.ready = Boolean(config.ready);
    state.storeUrl = String(config.storeUrl ?? '');
    state.pollDelay = Number(config.pollDelay ?? 1000);
    state.maxPollAttempts = Number(config.maxPollAttempts ?? 120);
    state.fetchRequest = config.fetchRequest ?? window.fetch.bind(window);
    state.schedule = config.schedule ?? window.setTimeout.bind(window);
    state.previewUrl = '';

    state.selectPhoto = function (event) {
        if (this.previewUrl) {
            urlApi?.revokeObjectURL?.(this.previewUrl);
            this.previewUrl = '';
        }

        const file = event?.target?.files?.[0];
        if (file?.type?.startsWith('image/')) {
            this.previewUrl = urlApi?.createObjectURL?.(file) ?? '';
        }
        this.message = '';
    };

    state.destroy = function () {
        if (this.previewUrl) {
            urlApi?.revokeObjectURL?.(this.previewUrl);
            this.previewUrl = '';
        }
    };

    state.analyze = async function (file, agencyId) {
        const sequence = this.beginAnalysis();
        if (! this.ready || ! file || ! agencyId || this.storeUrl === '') {
            this.fail(sequence);

            return;
        }

        const form = new FormData();
        form.append('agency_id', String(agencyId));
        form.append('image', file);

        try {
            const response = await this.fetchRequest(this.storeUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: requestHeaders(),
                body: form,
            });
            const payload = await response.json();
            if (sequence !== this.requestSequence) return;
            if (! response.ok
                || typeof payload.run_id !== 'string'
                || typeof payload.status_url !== 'string') {
                this.fail(sequence);

                return;
            }

            this.acceptStatus(sequence, payload.run_id, {
                status: payload.status,
                message: 'Analyse de la photo en cours…',
            });
            this.poll(sequence, payload.run_id, payload.status_url, 0);
        } catch {
            this.fail(sequence);
        }
    };

    state.poll = async function (sequence, runId, statusUrl, attempt) {
        if (sequence !== this.requestSequence) return;
        if (attempt >= this.maxPollAttempts) {
            this.fail(sequence);

            return;
        }

        try {
            const response = await this.fetchRequest(statusUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: requestHeaders(),
            });
            const payload = await response.json();
            if (sequence !== this.requestSequence) return;
            if (! response.ok || ! this.acceptStatus(sequence, runId, payload)) {
                this.fail(sequence);

                return;
            }
            if (ACTIVE_STATUSES.has(payload.status)) {
                this.schedule(
                    () => this.poll(sequence, runId, statusUrl, attempt + 1),
                    this.pollDelay,
                );
            }
        } catch {
            this.fail(sequence);
        }
    };

    return state;
}

function requestHeaders() {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    return {
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrf,
        'X-Requested-With': 'XMLHttpRequest',
    };
}

function safeMessage(value, fallback) {
    return typeof value === 'string' && value.trim() !== ''
        ? value.slice(0, 240)
        : fallback;
}
