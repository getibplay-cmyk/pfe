import { formatConfidence } from './business-number.js';

const ACTIVE_STATUSES = new Set(['queued', 'running']);
const PROCESSING_MESSAGE = 'Analyse de la photo en cours…';
const MANUAL_MESSAGE = 'La photo n’a pas pu être analysée. Poursuivez l’inspection manuelle.';
const HUMAN_NOTICE = 'Cette analyse est une aide visuelle. Vérifiez toujours l’ensemble du véhicule avant de valider le retour.';
const FORBIDDEN_CLIENT_TERMS = /\b(?:rt-?detr|ap50?|gate|runtime|worker|queue|checkpoint|artefact|artifact|sha|path|chemin|exception|traceback)\b/iu;

export function createReturnDamageAssistantState(initialNotes = '') {
    return {
        notes: String(initialNotes ?? ''),
        photos: [],
        selectedRunIds: [],
        nextPhotoId: 1,
        releasePreview: null,

        addFiles(files, createPreview = null) {
            for (const file of Array.from(files ?? []).slice(0, 12 - this.photos.length)) {
                const id = this.nextPhotoId;
                this.nextPhotoId += 1;
                this.photos.push({
                    id,
                    file,
                    name: safeFileName(file?.name, `Photo ${id}`),
                    preview: typeof createPreview === 'function' ? createPreview(file) : '',
                    phase: 'idle',
                    message: '',
                    notice: HUMAN_NOTICE,
                    detections: [],
                    runId: '',
                    suggestionText: '',
                    suggestionApplied: false,
                    requestSequence: 0,
                });
            }
        },

        beginAnalysis(photo) {
            this.removeSuggestion(photo);
            photo.requestSequence += 1;
            photo.phase = 'uploading';
            photo.message = PROCESSING_MESSAGE;
            photo.notice = HUMAN_NOTICE;
            photo.detections = [];
            photo.runId = '';
            photo.suggestionText = '';

            return photo.requestSequence;
        },

        acceptStatus(photo, sequence, runId, payload) {
            if (sequence !== photo.requestSequence
                || ! payload
                || typeof payload.status !== 'string') {
                return false;
            }
            if (ACTIVE_STATUSES.has(payload.status)) {
                photo.phase = 'processing';
                photo.message = safeMessage(payload.message, PROCESSING_MESSAGE);

                return true;
            }
            if (payload.status === 'failed') {
                return this.fail(photo, sequence);
            }
            if (payload.status !== 'succeeded'
                || ! Array.isArray(payload.detections)
                || typeof payload.notice !== 'string'
                || payload.notice !== HUMAN_NOTICE) {
                return this.fail(photo, sequence);
            }

            const detections = [];
            for (const detection of payload.detections) {
                const confidence = Number(detection?.confidence);
                const box = detection?.box;
                if (detection?.type !== 'possible_damage'
                    || detection?.label !== 'Zone de dommage possible'
                    || ! Number.isFinite(confidence)
                    || confidence < 0
                    || confidence > 1
                    || ! validBox(box)) {
                    return this.fail(photo, sequence);
                }
                detections.push({
                    type: detection.type,
                    label: detection.label,
                    confidence,
                    box: { ...box },
                });
            }

            photo.phase = 'succeeded';
            photo.message = safeMessage(
                payload.message,
                detections.length === 0
                    ? 'Aucun dommage n’a été suggéré sur cette photo. Poursuivez l’inspection visuelle du véhicule.'
                    : `${detections.length} zone(s) de dommage possible à vérifier visuellement.`,
            );
            photo.notice = HUMAN_NOTICE;
            photo.detections = detections;
            photo.runId = String(runId);
            photo.suggestionText = detections.length === 0
                ? ''
                : `Photo ${photo.id} : ${detections.length} zone(s) de dommage possible à vérifier visuellement.`;

            return true;
        },

        fail(photo, sequence, message = MANUAL_MESSAGE) {
            if (sequence !== photo.requestSequence) return false;

            this.removeSuggestion(photo);
            photo.phase = 'failed';
            photo.message = safeMessage(message, MANUAL_MESSAGE);
            photo.notice = HUMAN_NOTICE;
            photo.detections = [];
            photo.runId = '';
            photo.suggestionText = '';

            return true;
        },

        addSuggestion(photo) {
            if (photo.phase !== 'succeeded'
                || photo.detections.length === 0
                || photo.runId === ''
                || photo.suggestionText === '') {
                return false;
            }
            if (! this.notes.includes(photo.suggestionText)) {
                this.notes = [this.notes.trim(), photo.suggestionText]
                    .filter(Boolean)
                    .join('\n');
            }
            if (! this.selectedRunIds.includes(photo.runId)) {
                this.selectedRunIds.push(photo.runId);
            }
            photo.suggestionApplied = true;

            return true;
        },

        removeSuggestion(photo) {
            if (! photo) return false;
            if (photo.suggestionText !== '') {
                this.notes = this.notes
                    .split('\n')
                    .filter((line) => line.trim() !== photo.suggestionText)
                    .join('\n')
                    .trim();
            }
            this.selectedRunIds = this.selectedRunIds.filter((id) => id !== photo.runId);
            photo.suggestionApplied = false;

            return true;
        },

        removePhoto(photo) {
            this.removeSuggestion(photo);
            photo.requestSequence += 1;
            if (photo.preview && typeof this.releasePreview === 'function') {
                this.releasePreview(photo.preview);
                photo.preview = '';
            }
            this.photos = this.photos.filter((candidate) => candidate.id !== photo.id);
        },

        destroy() {
            for (const photo of this.photos) {
                if (photo.preview && typeof this.releasePreview === 'function') {
                    this.releasePreview(photo.preview);
                    photo.preview = '';
                }
            }
        },

        confidenceText(value) {
            const confidence = Number(value);
            if (! Number.isFinite(confidence)) return '';

            return formatConfidence(confidence);
        },
    };
}

export function createReturnDamageAssistant(config = {}) {
    const state = createReturnDamageAssistantState(config.initialNotes ?? '');
    const urlApi = config.urlApi ?? globalThis.window?.URL ?? globalThis.URL;
    state.ready = Boolean(config.ready);
    state.storeUrl = String(config.storeUrl ?? '');
    state.pollDelay = Number(config.pollDelay ?? 1500);
    state.maxPollAttempts = Number(config.maxPollAttempts ?? 120);
    state.fetchRequest = config.fetchRequest ?? window.fetch.bind(window);
    state.schedule = config.schedule ?? window.setTimeout.bind(window);
    state.releasePreview = (url) => urlApi?.revokeObjectURL?.(url);

    state.addSelectedFiles = function (files) {
        this.addFiles(files, (file) => urlApi?.createObjectURL?.(file) ?? '');
    };

    state.analyze = async function (photo) {
        const sequence = this.beginAnalysis(photo);
        if (! this.ready || ! photo?.file || this.storeUrl === '') {
            this.fail(photo, sequence);

            return;
        }

        const form = new FormData();
        form.append('image', photo.file);
        try {
            const response = await this.fetchRequest(this.storeUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: requestHeaders(),
                body: form,
            });
            const payload = await response.json();
            if (sequence !== photo.requestSequence) return;
            if (response.status !== 202
                || typeof payload.run_id !== 'string'
                || typeof payload.status !== 'string'
                || typeof payload.status_url !== 'string') {
                this.fail(photo, sequence);

                return;
            }

            this.acceptStatus(photo, sequence, payload.run_id, {
                status: payload.status,
                message: PROCESSING_MESSAGE,
            });
            this.poll(photo, sequence, payload.run_id, payload.status_url, 0);
        } catch {
            this.fail(photo, sequence);
        }
    };

    state.poll = async function (photo, sequence, runId, statusUrl, attempt) {
        if (sequence !== photo.requestSequence) return;
        if (attempt >= this.maxPollAttempts) {
            this.fail(photo, sequence);

            return;
        }
        try {
            const response = await this.fetchRequest(statusUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: requestHeaders(),
            });
            const payload = await response.json();
            if (sequence !== photo.requestSequence) return;
            if (! response.ok || ! this.acceptStatus(photo, sequence, runId, payload)) {
                this.fail(photo, sequence);

                return;
            }
            if (ACTIVE_STATUSES.has(payload.status)) {
                this.schedule(
                    () => this.poll(photo, sequence, runId, statusUrl, attempt + 1),
                    this.pollDelay,
                );
            }
        } catch {
            this.fail(photo, sequence);
        }
    };

    return state;
}

function validBox(box) {
    return box
        && Number.isInteger(box.x)
        && Number.isInteger(box.y)
        && Number.isInteger(box.width)
        && Number.isInteger(box.height)
        && box.x >= 0
        && box.y >= 0
        && box.width > 0
        && box.height > 0;
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
        ? value.slice(0, 300)
        : fallback;
}

function safeFileName(value, fallback) {
    return typeof value === 'string' && value.trim() !== ''
        ? value.replace(/[\r\n]/gu, '').slice(0, 120)
        : fallback;
}
