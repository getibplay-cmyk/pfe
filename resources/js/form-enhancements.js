const DEFAULT_PROGRESS_DELAY = 140;
const SAFE_LOADING_MESSAGE = 'Opération en cours…';

function setBusy(element, busy) {
    if (element?.setAttribute) element.setAttribute('aria-busy', busy ? 'true' : 'false');
}

function safeLoadingMessage(message) {
    const normalized = typeof message === 'string'
        ? message.replace(/\s+/gu, ' ').trim().slice(0, 120)
        : '';

    return normalized || SAFE_LOADING_MESSAGE;
}

export function createBelkhirSpaceLoadingController({
    progressElement = null,
    overlayElement = null,
    busyElement = null,
    delay = DEFAULT_PROGRESS_DELAY,
    setTimer = (callback, milliseconds) => globalThis.setTimeout(callback, milliseconds),
    clearTimer = (timer) => globalThis.clearTimeout(timer),
    prefersReducedMotion = false,
} = {}) {
    const activities = new Set();
    let timer = null;
    let visible = false;
    let longOperation = null;

    const render = (show) => {
        visible = show;

        if (progressElement) {
            progressElement.hidden = ! show;
            progressElement.dataset.state = show ? 'active' : 'idle';
            progressElement.dataset.reducedMotion = prefersReducedMotion ? 'true' : 'false';
        }

        setBusy(busyElement, show || Boolean(longOperation));
    };
    const clearPendingTimer = () => {
        if (timer === null) return;

        clearTimer(timer);
        timer = null;
    };
    const begin = ({ immediate = false } = {}) => {
        const token = Symbol('belkhir-space-loading');
        activities.add(token);

        if (immediate || delay <= 0) {
            clearPendingTimer();
            render(true);
        } else if (! visible && timer === null) {
            timer = setTimer(() => {
                timer = null;
                if (activities.size > 0) render(true);
            }, delay);
        }

        return token;
    };
    const finish = (token) => {
        if (token) activities.delete(token);

        if (activities.size === 0) {
            clearPendingTimer();
            render(false);
        }
    };
    const reset = () => {
        activities.clear();
        longOperation = null;
        clearPendingTimer();
        if (overlayElement) overlayElement.hidden = true;
        render(false);
    };
    const run = async (operation, { signal } = {}) => {
        const token = begin();
        const cancel = () => finish(token);

        if (signal?.aborted) {
            cancel();
            throw signal.reason ?? new DOMException('Opération annulée', 'AbortError');
        }

        signal?.addEventListener?.('abort', cancel, { once: true });

        try {
            return await (typeof operation === 'function' ? operation() : operation);
        } finally {
            signal?.removeEventListener?.('abort', cancel);
            finish(token);
        }
    };
    const startLongOperation = (message = SAFE_LOADING_MESSAGE) => {
        if (longOperation) finish(longOperation);
        longOperation = begin({ immediate: true });

        if (overlayElement) {
            const messageElement = overlayElement.querySelector?.('[data-belkhir-space-loading-message]');
            if (messageElement) messageElement.textContent = safeLoadingMessage(message);
            overlayElement.hidden = false;
        }

        setBusy(busyElement, true);

        return longOperation;
    };
    const endLongOperation = () => {
        const token = longOperation;
        longOperation = null;
        if (overlayElement) overlayElement.hidden = true;
        finish(token);
    };

    render(false);

    return {
        begin,
        finish,
        cancel: finish,
        fail: finish,
        reset,
        run,
        startLongOperation,
        endLongOperation,
        get active() { return activities.size > 0; },
        get visible() { return visible; },
    };
}

export function shouldTrackNavigation(event, link, location = globalThis.location) {
    if (! link || event?.defaultPrevented || event?.button > 0 || event?.metaKey || event?.ctrlKey || event?.shiftKey || event?.altKey) return false;
    if (link.hasAttribute?.('download') || link.dataset?.noGlobalLoading === 'true') return false;

    const target = link.getAttribute?.('target');
    if (target && target.toLowerCase() !== '_self') return false;

    const href = link.getAttribute?.('href');
    if (! href || href.startsWith('#')) return false;

    try {
        const destination = new URL(href, location?.href);
        const current = new URL(location?.href);

        if (! ['http:', 'https:'].includes(destination.protocol) || destination.origin !== current.origin) return false;

        return destination.pathname !== current.pathname
            || destination.search !== current.search
            || destination.hash === '';
    } catch {
        return false;
    }
}

export function initializeBelkhirSpaceLoading(root = document, host = window, options = {}) {
    const progressElement = root.querySelector?.('[data-belkhir-space-progress]') ?? null;
    const overlayElement = root.querySelector?.('[data-belkhir-space-loading-overlay]') ?? null;
    const controller = createBelkhirSpaceLoadingController({
        progressElement,
        overlayElement,
        busyElement: root.body,
        prefersReducedMotion: host.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ?? false,
        setTimer: host.setTimeout?.bind(host),
        clearTimer: host.clearTimeout?.bind(host),
        ...options,
    });
    let navigationToken = null;

    const onClick = (event) => {
        try {
            const link = event.target?.closest?.('a[href]');
            if (! shouldTrackNavigation(event, link, host.location)) return;

            (host.queueMicrotask ?? globalThis.queueMicrotask)(() => {
                if (! event.defaultPrevented && ! navigationToken) navigationToken = controller.begin();
            });
        } catch {
            controller.reset();
        }
    };
    const reset = () => {
        navigationToken = null;
        controller.reset();
    };
    const startLongOperation = (event) => controller.startLongOperation(event?.detail?.message);

    root.addEventListener?.('click', onClick);
    host.addEventListener?.('pageshow', reset);
    host.addEventListener?.('pagehide', reset);
    host.addEventListener?.('error', reset);
    host.addEventListener?.('unhandledrejection', reset);
    host.addEventListener?.('belkhir-space:loading-cancel', reset);
    host.addEventListener?.('belkhir-space:long-operation-start', startLongOperation);
    host.addEventListener?.('belkhir-space:long-operation-end', controller.endLongOperation);

    controller.destroy = () => {
        reset();
        root.removeEventListener?.('click', onClick);
        host.removeEventListener?.('pageshow', reset);
        host.removeEventListener?.('pagehide', reset);
        host.removeEventListener?.('error', reset);
        host.removeEventListener?.('unhandledrejection', reset);
        host.removeEventListener?.('belkhir-space:loading-cancel', reset);
        host.removeEventListener?.('belkhir-space:long-operation-start', startLongOperation);
        host.removeEventListener?.('belkhir-space:long-operation-end', controller.endLongOperation);
    };

    return controller;
}

export function createSubmissionGuard() {
    let submitting = false;

    return {
        begin() {
            if (submitting) return false;
            submitting = true;
            return true;
        },
        reset() { submitting = false; },
        get submitting() { return submitting; },
    };
}

export function initializeLoadingForms(root = document, host = window, loadingController = null) {
    const forms = Array.from(root.querySelectorAll('form[data-loading-form]'))
        .filter((form) => form.dataset.noGlobalLoading !== 'true');
    const schedule = host.queueMicrotask?.bind(host) ?? globalThis.queueMicrotask;

    for (const form of forms) {
        if (form.dataset.loadingReady === 'true') continue;

        const guard = createSubmissionGuard();
        form.dataset.loadingReady = 'true';
        form.addEventListener('submit', (event) => {
            if (! guard.begin()) {
                event.preventDefault();
                return;
            }

            schedule(() => {
                if (event.defaultPrevented) {
                    guard.reset();
                    return;
                }

                setFormLoading(form, true);
                form.__belkhirSpaceLoadingToken = loadingController?.begin();
            });
        });
        form.addEventListener('reset', () => {
            guard.reset();
            loadingController?.finish(form.__belkhirSpaceLoadingToken);
            form.__belkhirSpaceLoadingToken = null;
            setFormLoading(form, false);
        });
        form.__rentfleetSubmissionGuard = guard;
    }

    host.addEventListener('pageshow', () => {
        for (const form of forms) {
            form.__rentfleetSubmissionGuard?.reset();
            loadingController?.finish(form.__belkhirSpaceLoadingToken);
            form.__belkhirSpaceLoadingToken = null;
            setFormLoading(form, false);
        }
    });
}

export function setFormLoading(form, loading) {
    form.dataset.submitting = loading ? 'true' : 'false';
    form.setAttribute('aria-busy', loading ? 'true' : 'false');

    for (const button of form.querySelectorAll('[data-loading-submit]')) {
        if (loading) {
            button.dataset.loadingWasDisabled = button.disabled ? 'true' : 'false';
            button.disabled = true;
        } else {
            button.disabled = button.dataset.loadingWasDisabled === 'true';
            delete button.dataset.loadingWasDisabled;
        }
    }

    for (const spinner of form.querySelectorAll('[data-loading-spinner]')) spinner.hidden = ! loading;

    for (const icon of form.querySelectorAll('[data-loading-icon]')) {
        if (loading) {
            icon.dataset.loadingWasHidden = icon.hidden ? 'true' : 'false';
            icon.hidden = true;
        } else {
            icon.hidden = icon.dataset.loadingWasHidden === 'true';
            delete icon.dataset.loadingWasHidden;
        }
    }

    for (const label of form.querySelectorAll('[data-loading-label]')) {
        if (! label.dataset.loadingOriginal) label.dataset.loadingOriginal = label.textContent;
        label.textContent = loading
            ? (label.dataset.loadingText || 'Traitement en cours…')
            : label.dataset.loadingOriginal;
    }
}
