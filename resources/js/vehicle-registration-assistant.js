import { formatConfidence } from './business-number.js';

const ACTIVE_STATUSES = new Set(['queued', 'running']);
const INPUT_KINDS = new Set(['full_vehicle_image', 'plate_crop']);
const MANUAL_MESSAGE = 'L’immatriculation n’a pas pu être lue. Saisissez-la manuellement.';
const CLOSE_UP_MESSAGE = 'Plaque non détectée. Ajoutez une photo rapprochée de la plaque.';
const PROCESSING_MESSAGE = 'Lecture de la photo en cours…';
const FORBIDDEN_CLIENT_TERMS = /\b(?:anpr|ocr|paddleocr|faster(?:\s+r-cnn)?|checkpoint|runtime|worker|queue|sha|path|chemin|exception|traceback)\b/iu;

export function createVehicleRegistrationAssistantState(initialRegistration = '') {
    const initialValue = String(initialRegistration ?? '');

    return {
        phase: 'idle',
        registrationValue: initialValue,
        registrationWasEdited: initialValue.trim() !== '',
        suggestion: null,
        confidence: null,
        message: '',
        acceptedRunId: '',
        showUseSuggestion: false,
        showCloseUp: false,
        requiresCloseUp: false,
        activeMode: 'full_vehicle_image',
        requestSequence: 0,

        get busy() {
            return this.phase === 'uploading' || this.phase === 'processing';
        },

        beginAnalysis(inputKind = 'full_vehicle_image') {
            this.requestSequence += 1;
            this.activeMode = INPUT_KINDS.has(inputKind) ? inputKind : 'full_vehicle_image';
            this.phase = 'uploading';
            this.suggestion = null;
            this.confidence = null;
            this.message = PROCESSING_MESSAGE;
            this.acceptedRunId = '';
            this.showUseSuggestion = false;
            this.requiresCloseUp = false;
            if (this.activeMode === 'plate_crop') this.showCloseUp = true;

            return this.requestSequence;
        },

        markRegistrationEdited(value) {
            this.registrationValue = String(value ?? '');
            this.registrationWasEdited = true;
            this.showUseSuggestion = this.suggestion !== null
                && this.registrationValue !== this.suggestion.value;
        },

        acceptStatus(sequence, runId, payload) {
            if (sequence !== this.requestSequence || ! payload || typeof payload.status !== 'string') {
                return false;
            }

            if (ACTIVE_STATUSES.has(payload.status)) {
                this.phase = 'processing';
                this.message = safeMessage(payload.message, PROCESSING_MESSAGE);

                return true;
            }

            if (typeof payload.displayable !== 'boolean'
                || typeof payload.requires_close_up !== 'boolean') {
                return this.fail(sequence, this.activeMode === 'full_vehicle_image');
            }

            if (payload.status === 'failed') {
                return this.fail(
                    sequence,
                    payload.requires_close_up,
                    safeMessage(
                        payload.message,
                        payload.requires_close_up ? CLOSE_UP_MESSAGE : MANUAL_MESSAGE,
                    ),
                );
            }

            if (payload.status !== 'succeeded') {
                return this.fail(sequence, this.activeMode === 'full_vehicle_image');
            }

            if (! payload.displayable) {
                this.phase = 'fallback';
                this.suggestion = null;
                this.confidence = null;
                this.acceptedRunId = '';
                this.showUseSuggestion = false;
                this.requiresCloseUp = payload.requires_close_up;
                this.showCloseUp = this.showCloseUp || payload.requires_close_up;
                this.message = safeMessage(
                    payload.message,
                    payload.requires_close_up ? CLOSE_UP_MESSAGE : MANUAL_MESSAGE,
                );

                return true;
            }

            const candidate = payload.suggestion;
            const confidence = Number(payload.confidence);
            if (! candidate
                || typeof candidate.value !== 'string'
                || typeof candidate.label !== 'string'
                || candidate.value.trim() === ''
                || candidate.label.trim() === ''
                || ! Number.isFinite(confidence)
                || confidence < 0
                || confidence > 1
                || payload.requires_close_up) {
                return this.fail(sequence, this.activeMode === 'full_vehicle_image');
            }

            this.phase = 'succeeded';
            this.suggestion = {
                value: candidate.value,
                label: candidate.label,
            };
            this.confidence = confidence;
            this.message = safeMessage(
                payload.message,
                'Vérifiez l’immatriculation avant d’enregistrer le véhicule.',
            );
            this.acceptedRunId = String(runId);
            this.requiresCloseUp = false;
            if (this.registrationWasEdited) {
                this.showUseSuggestion = this.registrationValue !== this.suggestion.value;
            } else {
                this.registrationValue = this.suggestion.value;
                this.showUseSuggestion = false;
            }

            return true;
        },

        fail(sequence, requiresCloseUp = false, message = MANUAL_MESSAGE) {
            if (sequence !== this.requestSequence) {
                return false;
            }

            this.phase = 'failed';
            this.suggestion = null;
            this.confidence = null;
            this.message = safeMessage(
                message,
                requiresCloseUp ? CLOSE_UP_MESSAGE : MANUAL_MESSAGE,
            );
            this.acceptedRunId = '';
            this.showUseSuggestion = false;
            this.requiresCloseUp = Boolean(requiresCloseUp);
            this.showCloseUp = this.showCloseUp || this.requiresCloseUp;

            return true;
        },

        openCloseUp() {
            this.showCloseUp = true;
        },

        useSuggestion() {
            if (this.suggestion === null) {
                return false;
            }

            this.registrationValue = this.suggestion.value;
            this.registrationWasEdited = true;
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

export function createVehicleRegistrationAssistant(config = {}) {
    const state = createVehicleRegistrationAssistantState(config.initialRegistration ?? '');
    const urlApi = config.urlApi ?? globalThis.window?.URL ?? globalThis.URL;
    state.readyFull = Boolean(config.readyFull);
    state.readyCloseUp = Boolean(config.readyCloseUp);
    state.storeUrl = String(config.storeUrl ?? '');
    state.pollDelay = Number(config.pollDelay ?? 1500);
    state.maxPollAttempts = Number(config.maxPollAttempts ?? 240);
    state.fetchRequest = config.fetchRequest ?? window.fetch.bind(window);
    state.schedule = config.schedule ?? window.setTimeout.bind(window);
    state.fullPreviewUrl = '';
    state.closeUpPreviewUrl = '';

    state.selectPhoto = function (event, inputKind) {
        const property = inputKind === 'plate_crop' ? 'closeUpPreviewUrl' : 'fullPreviewUrl';
        if (this[property]) {
            urlApi?.revokeObjectURL?.(this[property]);
            this[property] = '';
        }

        const file = event?.target?.files?.[0];
        if (file?.type?.startsWith('image/')) {
            this[property] = urlApi?.createObjectURL?.(file) ?? '';
        }
        this.message = '';
    };

    state.destroy = function () {
        for (const property of ['fullPreviewUrl', 'closeUpPreviewUrl']) {
            if (this[property]) {
                urlApi?.revokeObjectURL?.(this[property]);
                this[property] = '';
            }
        }
    };

    state.analyze = async function (file, agencyId, inputKind) {
        const sequence = this.beginAnalysis(inputKind);
        const modeReady = inputKind === 'plate_crop' ? this.readyCloseUp : this.readyFull;
        if (! INPUT_KINDS.has(inputKind)
            || ! modeReady
            || ! file
            || ! agencyId
            || this.storeUrl === '') {
            this.fail(sequence, inputKind === 'full_vehicle_image');

            return;
        }

        const form = new FormData();
        form.append('agency_id', String(agencyId));
        form.append('input_kind', inputKind);
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
                this.fail(sequence, inputKind === 'full_vehicle_image');

                return;
            }

            this.acceptStatus(sequence, payload.run_id, {
                status: payload.status,
                message: PROCESSING_MESSAGE,
            });
            this.poll(sequence, payload.run_id, payload.status_url, 0);
        } catch {
            this.fail(sequence, inputKind === 'full_vehicle_image');
        }
    };

    state.poll = async function (sequence, runId, statusUrl, attempt) {
        if (sequence !== this.requestSequence) return;
        if (attempt >= this.maxPollAttempts) {
            this.fail(
                sequence,
                this.activeMode === 'full_vehicle_image',
                this.activeMode === 'full_vehicle_image'
                    ? CLOSE_UP_MESSAGE
                    : MANUAL_MESSAGE,
            );

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
                this.fail(sequence, this.activeMode === 'full_vehicle_image');

                return;
            }
            if (ACTIVE_STATUSES.has(payload.status)) {
                this.schedule(
                    () => this.poll(sequence, runId, statusUrl, attempt + 1),
                    this.pollDelay,
                );
            }
        } catch {
            this.fail(sequence, this.activeMode === 'full_vehicle_image');
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
    return typeof value === 'string'
        && value.trim() !== ''
        && ! FORBIDDEN_CLIENT_TERMS.test(value)
        ? value.slice(0, 240)
        : fallback;
}
