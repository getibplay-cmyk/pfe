import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import {
    createBelkhirSpaceLoadingController,
    createSubmissionGuard,
    initializeBelkhirSpaceLoading,
    initializeLoadingForms,
    setFormLoading,
    shouldTrackNavigation,
} from '../../resources/js/form-enhancements.js';

function fakeForm() {
    const listeners = new Map();
    const button = { disabled: false, dataset: {} };
    const spinner = { hidden: true };
    const icon = { hidden: false, dataset: {} };
    const label = { textContent: 'Appliquer', dataset: { loadingText: 'Application…' } };

    return {
        dataset: {},
        attributes: {},
        button,
        spinner,
        icon,
        label,
        addEventListener(name, listener) { listeners.set(name, listener); },
        dispatch(name, event = {}) { listeners.get(name)?.(event); },
        setAttribute(name, value) { this.attributes[name] = value; },
        querySelectorAll(selector) {
            if (selector === '[data-loading-submit]') return [button];
            if (selector === '[data-loading-spinner]') return [spinner];
            if (selector === '[data-loading-icon]') return [icon];
            if (selector === '[data-loading-label]') return [label];
            return [];
        },
    };
}

function fakeLoadingElements() {
    const message = { textContent: '' };
    const progress = { hidden: true, dataset: {} };
    const overlay = {
        hidden: true,
        querySelector: () => message,
    };
    const busy = {
        attributes: {},
        setAttribute(name, value) { this.attributes[name] = value; },
    };

    return { progress, overlay, message, busy };
}

function timerHarness() {
    let callback = null;
    let milliseconds = null;

    return {
        setTimer(next, delay) {
            callback = next;
            milliseconds = delay;
            return 1;
        },
        clearTimer() { callback = null; },
        fire() {
            const pending = callback;
            callback = null;
            pending?.();
        },
        get delay() { return milliseconds; },
        get pending() { return callback !== null; },
    };
}

test('le spinner masqué reste invisible malgré sa classe inline-block', () => {
    const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');

    assert.match(css, /\.rf-spinner\[hidden\][^{]*\{\s*display:\s*none\s*!important;\s*\}/u);
});

test('la barre attend 140 ms, expose aria-busy puis se nettoie après succès', () => {
    const elements = fakeLoadingElements();
    const timers = timerHarness();
    const controller = createBelkhirSpaceLoadingController({
        progressElement: elements.progress,
        busyElement: elements.busy,
        setTimer: timers.setTimer,
        clearTimer: timers.clearTimer,
    });

    const token = controller.begin();
    assert.equal(timers.delay, 140);
    assert.equal(elements.progress.hidden, true);
    timers.fire();
    assert.equal(elements.progress.hidden, false);
    assert.equal(elements.busy.attributes['aria-busy'], 'true');

    controller.finish(token);
    assert.equal(elements.progress.hidden, true);
    assert.equal(elements.busy.attributes['aria-busy'], 'false');
});

test('une opération terminée avant le délai ne fait jamais clignoter la barre', () => {
    const elements = fakeLoadingElements();
    const timers = timerHarness();
    const controller = createBelkhirSpaceLoadingController({
        progressElement: elements.progress,
        setTimer: timers.setTimer,
        clearTimer: timers.clearTimer,
    });

    const token = controller.begin();
    controller.finish(token);

    assert.equal(timers.pending, false);
    assert.equal(elements.progress.hidden, true);
});

test('succès, erreur et exception précoce nettoient toujours le contrôleur', async () => {
    const controller = createBelkhirSpaceLoadingController({ delay: 0 });

    assert.equal(await controller.run(() => Promise.resolve('ok')), 'ok');
    assert.equal(controller.active, false);
    await assert.rejects(controller.run(() => Promise.reject(new Error('échec'))), /échec/u);
    assert.equal(controller.active, false);
    await assert.rejects(controller.run(() => { throw new Error('précoce'); }), /précoce/u);
    assert.equal(controller.active, false);
});

test('une annulation déjà signalée ne laisse aucun état actif', async () => {
    const controller = createBelkhirSpaceLoadingController({ delay: 0 });
    const abort = new AbortController();
    abort.abort(new Error('annulée'));

    await assert.rejects(controller.run(() => Promise.resolve(), { signal: abort.signal }), /annulée/u);
    assert.equal(controller.active, false);
    assert.equal(controller.visible, false);
});

test('une annulation pendant une opération nettoie avant même sa résolution', async () => {
    const controller = createBelkhirSpaceLoadingController({ delay: 0 });
    const abort = new AbortController();
    let resolveOperation;
    const operation = controller.run(
        () => new Promise((resolve) => { resolveOperation = resolve; }),
        { signal: abort.signal },
    );

    assert.equal(controller.active, true);
    abort.abort();
    assert.equal(controller.active, false);
    assert.equal(controller.visible, false);

    resolveOperation('terminée');
    assert.equal(await operation, 'terminée');
    assert.equal(controller.active, false);
});

test('le plein écran reste explicite et écrit le message comme texte sûr', () => {
    const elements = fakeLoadingElements();
    const controller = createBelkhirSpaceLoadingController({
        progressElement: elements.progress,
        overlayElement: elements.overlay,
        busyElement: elements.busy,
        delay: 0,
    });

    assert.equal(elements.overlay.hidden, true);
    controller.startLongOperation('<img src=x onerror=alert(1)>');
    assert.equal(elements.overlay.hidden, false);
    assert.equal(elements.message.textContent, '<img src=x onerror=alert(1)>');
    controller.endLongOperation();
    assert.equal(elements.overlay.hidden, true);
    assert.equal(elements.busy.attributes['aria-busy'], 'false');
});

test('le mode reduced motion est transmis à la barre', () => {
    const elements = fakeLoadingElements();
    const controller = createBelkhirSpaceLoadingController({
        progressElement: elements.progress,
        prefersReducedMotion: true,
        delay: 0,
    });

    controller.begin();
    assert.equal(elements.progress.dataset.reducedMotion, 'true');
});

test('seules les navigations internes ordinaires sont suivies', () => {
    const location = { href: 'https://rentfleet.test/reservations' };
    const link = (href, { target = null, download = false } = {}) => ({
        dataset: {},
        hasAttribute: (name) => name === 'download' && download,
        getAttribute: (name) => name === 'href' ? href : target,
    });
    const click = { button: 0 };

    assert.equal(shouldTrackNavigation(click, link('/vehicles'), location), true);
    assert.equal(shouldTrackNavigation(click, link('https://example.com'), location), false);
    assert.equal(shouldTrackNavigation(click, link('/export', { download: true }), location), false);
    assert.equal(shouldTrackNavigation(click, link('/vehicles', { target: '_blank' }), location), false);
    assert.equal(shouldTrackNavigation({ ...click, ctrlKey: true }, link('/vehicles'), location), false);
    assert.equal(shouldTrackNavigation(click, link('#filtres'), location), false);
});

test('pageshow, erreur et annulation globale réinitialisent une navigation', () => {
    const elements = fakeLoadingElements();
    const rootListeners = new Map();
    const hostListeners = new Map();
    const root = {
        body: elements.busy,
        querySelector(selector) {
            if (selector === '[data-belkhir-space-progress]') return elements.progress;
            if (selector === '[data-belkhir-space-loading-overlay]') return elements.overlay;
            return null;
        },
        addEventListener: (name, listener) => rootListeners.set(name, listener),
        removeEventListener() {},
    };
    const host = {
        location: { href: 'https://rentfleet.test/reservations' },
        matchMedia: () => ({ matches: false }),
        queueMicrotask: (callback) => callback(),
        setTimeout: (callback) => { callback(); return 1; },
        clearTimeout() {},
        addEventListener: (name, listener) => hostListeners.set(name, listener),
        removeEventListener() {},
    };
    const link = {
        dataset: {},
        hasAttribute: () => false,
        getAttribute: (name) => name === 'href' ? '/vehicles' : null,
    };
    const controller = initializeBelkhirSpaceLoading(root, host, { delay: 0 });

    rootListeners.get('click')({ button: 0, target: { closest: () => link } });
    assert.equal(controller.active, true);
    hostListeners.get('pageshow')();
    assert.equal(controller.active, false);

    rootListeners.get('click')({ button: 0, target: { closest: () => link } });
    hostListeners.get('error')();
    assert.equal(controller.active, false);

    rootListeners.get('click')({ button: 0, target: { closest: () => link } });
    hostListeners.get('belkhir-space:loading-cancel')();
    assert.equal(controller.active, false);
});

test('un double clic de navigation ne crée qu’une activité différée', () => {
    const rootListeners = new Map();
    let scheduled = 0;
    const root = {
        body: { setAttribute() {} },
        querySelector: () => null,
        addEventListener: (name, listener) => rootListeners.set(name, listener),
        removeEventListener() {},
    };
    const host = {
        location: { href: 'https://rentfleet.test/reservations' },
        matchMedia: () => ({ matches: false }),
        queueMicrotask: (callback) => callback(),
        setTimeout: () => { scheduled += 1; return scheduled; },
        clearTimeout() {},
        addEventListener() {},
        removeEventListener() {},
    };
    const link = {
        dataset: {},
        hasAttribute: () => false,
        getAttribute: (name) => name === 'href' ? '/vehicles' : null,
    };
    const click = { button: 0, target: { closest: () => link } };

    initializeBelkhirSpaceLoading(root, host);
    rootListeners.get('click')(click);
    rootListeners.get('click')(click);

    assert.equal(scheduled, 1);
});

test('la garde refuse une seconde soumission jusqu’à sa réinitialisation', () => {
    const guard = createSubmissionGuard();

    assert.equal(guard.begin(), true);
    assert.equal(guard.begin(), false);
    guard.reset();
    assert.equal(guard.begin(), true);
});

test('l’état de chargement reste accessible et restaure le bouton', () => {
    const form = fakeForm();

    setFormLoading(form, true);
    assert.equal(form.attributes['aria-busy'], 'true');
    assert.equal(form.button.disabled, true);
    assert.equal(form.spinner.hidden, false);
    assert.equal(form.icon.hidden, true);
    assert.equal(form.label.textContent, 'Application…');

    setFormLoading(form, false);
    assert.equal(form.attributes['aria-busy'], 'false');
    assert.equal(form.button.disabled, false);
    assert.equal(form.spinner.hidden, true);
    assert.equal(form.icon.hidden, false);
    assert.equal(form.label.textContent, 'Appliquer');
});

test('le branchement formulaire bloque le double clic puis pageshow le réarme', () => {
    const form = fakeForm();
    const hostListeners = new Map();
    let loadingStarts = 0;
    const loadingController = {
        begin() { loadingStarts += 1; return Symbol('form'); },
        finish() {},
    };
    initializeLoadingForms(
        { querySelectorAll: () => [form] },
        {
            queueMicrotask: (callback) => callback(),
            addEventListener: (name, listener) => hostListeners.set(name, listener),
        },
        loadingController,
    );

    let prevented = false;
    form.dispatch('submit', { defaultPrevented: false, preventDefault: () => { prevented = true; } });
    assert.equal(form.dataset.submitting, 'true');
    assert.equal(loadingStarts, 1);
    form.dispatch('submit', { defaultPrevented: false, preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);

    hostListeners.get('pageshow')();
    assert.equal(form.dataset.submitting, 'false');
    assert.equal(form.button.disabled, false);
});

test('une soumission annulée avant navigation ne verrouille pas le formulaire', () => {
    const form = fakeForm();
    initializeLoadingForms(
        { querySelectorAll: () => [form] },
        { queueMicrotask: (callback) => callback(), addEventListener() {} },
    );

    form.dispatch('submit', { defaultPrevented: true, preventDefault() {} });
    assert.notEqual(form.dataset.submitting, 'true');
    assert.equal(form.__rentfleetSubmissionGuard.submitting, false);
});

test('un formulaire de téléchargement explicite est entièrement ignoré', () => {
    const form = fakeForm();
    form.dataset.noGlobalLoading = 'true';
    let loadingStarts = 0;

    initializeLoadingForms(
        { querySelectorAll: () => [form] },
        { queueMicrotask: (callback) => callback(), addEventListener() {} },
        { begin: () => { loadingStarts += 1; } },
    );

    form.dispatch('submit', { defaultPrevented: false, preventDefault() {} });
    assert.equal(form.dataset.loadingReady, undefined);
    assert.equal(form.dataset.submitting, undefined);
    assert.equal(loadingStarts, 0);
});
