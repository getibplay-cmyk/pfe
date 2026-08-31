import test from 'node:test';
import assert from 'node:assert/strict';

import { createVehicleColorAssistantState } from '../../resources/js/vehicle-color-assistant.js';
import {
    createVehicleRegistrationAssistant,
    createVehicleRegistrationAssistantState,
} from '../../resources/js/vehicle-registration-assistant.js';

const complete = (confidence = 0.61) => ({
    status: 'succeeded',
    suggestion: { value: '12345|أ|7', label: '12345 | أ | 7' },
    confidence,
    displayable: true,
    requires_close_up: false,
    message: 'Vérifiez l’immatriculation avant d’enregistrer le véhicule.',
});

test('préremplit seulement une immatriculation intacte avec une suggestion complète', () => {
    const state = createVehicleRegistrationAssistantState('');
    const sequence = state.beginAnalysis();

    assert.equal(state.acceptStatus(sequence, 'run-current', complete()), true);
    assert.equal(state.registrationValue, '12345|أ|7');
    assert.equal(state.acceptedRunId, 'run-current');
    assert.equal(state.showUseSuggestion, false);
});

test('protège toute saisie humaine et propose une action explicite', () => {
    const state = createVehicleRegistrationAssistantState('');
    const sequence = state.beginAnalysis();
    state.markRegistrationEdited('VALEUR-HUMAINE');

    state.acceptStatus(sequence, 'run-current', complete());

    assert.equal(state.registrationValue, 'VALEUR-HUMAINE');
    assert.equal(state.showUseSuggestion, true);
    assert.equal(state.useSuggestion(), true);
    assert.equal(state.registrationValue, '12345|أ|7');
    assert.equal(state.showUseSuggestion, false);
});

test('protège aussi une valeur humaine restaurée après une erreur de formulaire', () => {
    const state = createVehicleRegistrationAssistantState('VALEUR-RESTAURÉE');
    const sequence = state.beginAnalysis();

    state.acceptStatus(sequence, 'run-current', complete());

    assert.equal(state.registrationValue, 'VALEUR-RESTAURÉE');
    assert.equal(state.showUseSuggestion, true);
});

test('ignore une réponse obsolète après une nouvelle analyse', () => {
    const state = createVehicleRegistrationAssistantState('');
    const stale = state.beginAnalysis();
    const current = state.beginAnalysis('plate_crop');

    assert.equal(state.acceptStatus(stale, 'run-stale', complete()), false);
    assert.equal(state.acceptedRunId, '');
    assert.equal(state.activeMode, 'plate_crop');
    assert.equal(state.acceptStatus(current, 'run-current', complete()), true);
    assert.equal(state.acceptedRunId, 'run-current');
});

test('un échec de photo complète révèle le parcours rapproché', () => {
    const state = createVehicleRegistrationAssistantState('SAISIE');
    const sequence = state.beginAnalysis('full_vehicle_image');

    state.acceptStatus(sequence, 'run-full', {
        status: 'failed',
        suggestion: null,
        confidence: null,
        displayable: false,
        requires_close_up: true,
        message: 'Plaque non détectée. Ajoutez une photo rapprochée de la plaque.',
    });

    assert.equal(state.phase, 'failed');
    assert.equal(state.showCloseUp, true);
    assert.equal(state.registrationValue, 'SAISIE');
    assert.equal(state.acceptedRunId, '');
});

test('une lecture partielle révèle le fallback sans jamais préremplir', () => {
    const state = createVehicleRegistrationAssistantState('');
    const sequence = state.beginAnalysis('plate_crop');

    state.acceptStatus(sequence, 'run-partial', {
        status: 'succeeded',
        suggestion: null,
        confidence: null,
        displayable: false,
        requires_close_up: true,
        message: 'Lecture incomplète. Vérifiez manuellement ou essayez une photo rapprochée.',
    });

    assert.equal(state.phase, 'fallback');
    assert.equal(state.registrationValue, '');
    assert.equal(state.acceptedRunId, '');
    assert.equal(state.showCloseUp, true);
});

test('une photo rapprochée complète peut produire la proposition canonique', () => {
    const state = createVehicleRegistrationAssistantState('');
    const sequence = state.beginAnalysis('plate_crop');

    state.acceptStatus(sequence, 'run-close-up', complete(0.08));

    assert.equal(state.phase, 'succeeded');
    assert.equal(state.activeMode, 'plate_crop');
    assert.equal(state.registrationValue, '12345|أ|7');
    assert.equal(state.confidence, 0.08);
});

test('refuse côté client une confiance non finie ou hors intervalle', () => {
    for (const confidence of [Number.NaN, Number.POSITIVE_INFINITY, -0.1, 1.1]) {
        const state = createVehicleRegistrationAssistantState('MANUEL');
        const sequence = state.beginAnalysis();
        state.acceptStatus(sequence, 'run-invalid', complete(confidence));

        assert.equal(state.phase, 'failed');
        assert.equal(state.registrationValue, 'MANUEL');
        assert.equal(state.acceptedRunId, '');
    }
});

test('le polling est borné et rend immédiatement la main à la saisie manuelle', async () => {
    const state = createVehicleRegistrationAssistant({
        readyFull: true,
        readyCloseUp: true,
        storeUrl: '/vehicles/registration-assistant',
        maxPollAttempts: 0,
        fetchRequest: async () => {
            throw new Error('ne doit pas être appelé');
        },
        schedule: () => {
            throw new Error('ne doit pas être planifié');
        },
    });
    const sequence = state.beginAnalysis('full_vehicle_image');

    await state.poll(sequence, 'run-timeout', '/status', 0);

    assert.equal(state.phase, 'failed');
    assert.equal(state.busy, false);
    assert.equal(state.showCloseUp, true);
});

test('les assistants couleur et immatriculation conservent des états indépendants', () => {
    const color = createVehicleColorAssistantState('Rouge');
    const registration = createVehicleRegistrationAssistantState('RF-MANUEL');
    const colorSequence = color.beginAnalysis();
    const registrationSequence = registration.beginAnalysis();

    color.acceptStatus(colorSequence, 'color-run', {
        status: 'succeeded',
        suggested_color: { value: 'blue', label: 'Bleu' },
        confidence: 0.8,
        message: 'Vous pouvez modifier cette couleur avant l’enregistrement.',
    });
    registration.acceptStatus(registrationSequence, 'plate-run', complete());

    assert.equal(color.colorValue, 'Bleu');
    assert.equal(color.acceptedRunId, 'color-run');
    assert.equal(registration.registrationValue, 'RF-MANUEL');
    assert.equal(registration.acceptedRunId, 'plate-run');
    assert.equal(registration.showUseSuggestion, true);
});

test('aucun message technique reçu ne peut être rendu au client', () => {
    const forbidden = ['ANPR', 'OCR', 'PaddleOCR', 'Faster R-CNN', 'checkpoint', 'runtime', 'worker', 'queue', 'SHA', 'chemin', 'exception', 'traceback'];
    for (const term of forbidden) {
        const state = createVehicleRegistrationAssistantState('');
        const sequence = state.beginAnalysis();
        state.acceptStatus(sequence, 'run-safe', {
            ...complete(),
            message: `Erreur ${term} interne`,
        });

        assert.equal(state.message, 'Vérifiez l’immatriculation avant d’enregistrer le véhicule.');
        assert.equal(state.message.toLowerCase().includes(term.toLowerCase()), false);
    }
});

test('les aperçus complet et rapproché sont indépendants puis révoqués', () => {
    const revoked = [];
    const assistant = createVehicleRegistrationAssistant({
        fetchRequest: async () => {},
        schedule: () => {},
        urlApi: {
            createObjectURL: (file) => `blob:${file.name}`,
            revokeObjectURL: (url) => revoked.push(url),
        },
    });

    assistant.selectPhoto({ target: { files: [{ name: 'vehicule.jpg', type: 'image/jpeg' }] } }, 'full_vehicle_image');
    assistant.selectPhoto({ target: { files: [{ name: 'plaque.jpg', type: 'image/jpeg' }] } }, 'plate_crop');
    assistant.selectPhoto({ target: { files: [{ name: 'plaque-2.jpg', type: 'image/jpeg' }] } }, 'plate_crop');
    assistant.destroy();

    assert.deepEqual(revoked, ['blob:plaque.jpg', 'blob:vehicule.jpg', 'blob:plaque-2.jpg']);
});
