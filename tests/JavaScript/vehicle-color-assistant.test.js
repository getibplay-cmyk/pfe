import test from 'node:test';
import assert from 'node:assert/strict';

import {
    createVehicleColorAssistant,
    createVehicleColorAssistantState,
} from '../../resources/js/vehicle-color-assistant.js';

const succeeded = (value = 'white', label = 'Blanc', confidence = 0.775) => ({
    status: 'succeeded',
    suggested_color: { value, label },
    confidence,
    message: 'Vous pouvez modifier cette couleur avant l’enregistrement.',
});

test('préremplit automatiquement une couleur non modifiée', () => {
    const state = createVehicleColorAssistantState('');
    const sequence = state.beginAnalysis();

    assert.equal(state.acceptStatus(sequence, 'run-latest', succeeded()), true);
    assert.equal(state.colorValue, 'Blanc');
    assert.equal(state.acceptedRunId, 'run-latest');
    assert.equal(state.showUseSuggestion, false);
});

test('ne remplace jamais une valeur modifiée manuellement', () => {
    const state = createVehicleColorAssistantState('');
    const sequence = state.beginAnalysis();
    state.markColorEdited('Bleu personnalisé');

    state.acceptStatus(sequence, 'run-latest', succeeded());

    assert.equal(state.colorValue, 'Bleu personnalisé');
    assert.equal(state.showUseSuggestion, true);
});

test('ignore une réponse obsolète', () => {
    const state = createVehicleColorAssistantState('');
    const stale = state.beginAnalysis();
    state.beginAnalysis();

    assert.equal(state.acceptStatus(stale, 'run-stale', succeeded()), false);
    assert.equal(state.acceptedRunId, '');
    assert.equal(state.colorValue, '');
});

test('seul le dernier run peut modifier l’interface', () => {
    const state = createVehicleColorAssistantState('');
    const stale = state.beginAnalysis();
    const latest = state.beginAnalysis();

    state.acceptStatus(latest, 'run-latest', succeeded('blue', 'Bleu', 0.81));
    state.acceptStatus(stale, 'run-stale', succeeded());

    assert.equal(state.colorValue, 'Bleu');
    assert.equal(state.acceptedRunId, 'run-latest');
});

test('expose le loader pendant l’envoi et le traitement', () => {
    const state = createVehicleColorAssistantState('');
    const sequence = state.beginAnalysis();

    assert.equal(state.busy, true);
    state.acceptStatus(sequence, 'run-latest', {
        status: 'running',
        message: 'Analyse de la photo en cours…',
    });
    assert.equal(state.phase, 'processing');
    assert.equal(state.busy, true);
});

test('un échec rend immédiatement le formulaire manuel', () => {
    const state = createVehicleColorAssistantState('Rouge');
    const sequence = state.beginAnalysis();

    state.fail(sequence);

    assert.equal(state.phase, 'failed');
    assert.equal(state.busy, false);
    assert.equal(state.colorValue, 'Rouge');
    assert.equal(state.acceptedRunId, '');
});

test('le bouton applique explicitement une suggestion protégée', () => {
    const state = createVehicleColorAssistantState('');
    const sequence = state.beginAnalysis();
    state.markColorEdited('Noir');
    state.acceptStatus(sequence, 'run-latest', succeeded());

    assert.equal(state.useSuggestion(), true);
    assert.equal(state.colorValue, 'Blanc');
    assert.equal(state.showUseSuggestion, false);
});

test('le remplacement de photo révoque l’ancien aperçu local', () => {
    const revoked = [];
    const assistant = createVehicleColorAssistant({
        fetchRequest: async () => {},
        schedule: () => {},
        urlApi: {
            createObjectURL: (file) => `blob:${file.name}`,
            revokeObjectURL: (url) => revoked.push(url),
        },
    });

    assistant.selectPhoto({ target: { files: [{ name: 'avant.jpg', type: 'image/jpeg' }] } });
    assistant.selectPhoto({ target: { files: [{ name: 'apres.jpg', type: 'image/jpeg' }] } });
    assistant.destroy();

    assert.deepEqual(revoked, ['blob:avant.jpg', 'blob:apres.jpg']);
});
