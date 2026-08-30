import test from 'node:test';
import assert from 'node:assert/strict';

import {
    createReturnDamageAssistant,
    createReturnDamageAssistantState,
} from '../../resources/js/return-damage-assistant.js';

const notice = 'Cette analyse est une aide visuelle. Vérifiez toujours l’ensemble du véhicule avant de valider le retour.';
const succeeded = (detections = [{
    type: 'possible_damage',
    label: 'Zone de dommage possible',
    confidence: 0.91,
    box: { x: 5, y: 10, width: 30, height: 40 },
}]) => ({
    status: 'succeeded',
    detections,
    message: detections.length === 0
        ? 'Aucun dommage n’a été suggéré sur cette photo. Poursuivez l’inspection visuelle du véhicule.'
        : '1 zone de dommage possible à vérifier visuellement.',
    notice,
    preview_url: '/private-preview',
});

function photoState(notes = '') {
    const state = createReturnDamageAssistantState(notes);
    state.addFiles([{ name: 'retour.jpg' }]);

    return [state, state.photos[0]];
}

test('une analyse reste consultative jusqu’à une action explicite', () => {
    const [state, photo] = photoState('Observation humaine');
    const sequence = state.beginAnalysis(photo);

    assert.equal(state.acceptStatus(photo, sequence, 'run-1', succeeded()), true);
    assert.equal(state.notes, 'Observation humaine');
    assert.deepEqual(state.selectedRunIds, []);
    assert.equal(photo.phase, 'succeeded');
});

test('plusieurs photos conservent des états indépendants', () => {
    const state = createReturnDamageAssistantState('');
    state.addFiles([{ name: 'a.jpg' }, { name: 'b.jpg' }]);
    const firstSequence = state.beginAnalysis(state.photos[0]);
    const secondSequence = state.beginAnalysis(state.photos[1]);

    state.acceptStatus(state.photos[0], firstSequence, 'run-a', succeeded());
    state.acceptStatus(state.photos[1], secondSequence, 'run-b', succeeded([]));

    assert.equal(state.photos[0].detections.length, 1);
    assert.equal(state.photos[1].detections.length, 0);
    assert.equal(state.photos[1].message.includes('Poursuivez'), true);
});

test('le loader et aria-live peuvent suivre les états non terminaux', () => {
    const [state, photo] = photoState();
    const sequence = state.beginAnalysis(photo);
    assert.equal(photo.phase, 'uploading');

    state.acceptStatus(photo, sequence, 'run-1', {
        status: 'running',
        message: 'Analyse de la photo en cours…',
    });

    assert.equal(photo.phase, 'processing');
});

test('une réponse obsolète est ignorée après une nouvelle analyse', () => {
    const [state, photo] = photoState();
    const stale = state.beginAnalysis(photo);
    state.beginAnalysis(photo);

    assert.equal(state.acceptStatus(photo, stale, 'run-stale', succeeded()), false);
    assert.equal(photo.runId, '');
});

test('ajouter, dédupliquer puis supprimer une suggestion préserve le reste des observations', () => {
    const [state, photo] = photoState('Rayure constatée manuellement.');
    const sequence = state.beginAnalysis(photo);
    state.acceptStatus(photo, sequence, 'run-1', succeeded());

    assert.equal(state.addSuggestion(photo), true);
    assert.equal(state.addSuggestion(photo), true);
    assert.equal(state.notes.match(/Photo 1/gu)?.length, 1);
    assert.deepEqual(state.selectedRunIds, ['run-1']);

    state.removeSuggestion(photo);
    assert.equal(state.notes, 'Rayure constatée manuellement.');
    assert.deepEqual(state.selectedRunIds, []);
});

test('les observations humaines peuvent être différentes du résultat', () => {
    const [state, photo] = photoState('Texte libre du contrôleur.');
    const sequence = state.beginAnalysis(photo);
    state.acceptStatus(photo, sequence, 'run-1', succeeded());

    state.notes = 'Description humaine modifiée sans coût ni responsabilité.';

    assert.equal(state.notes, 'Description humaine modifiée sans coût ni responsabilité.');
    assert.deepEqual(state.selectedRunIds, []);
});

test('zéro détection ne peut pas être ajouté comme dommage', () => {
    const [state, photo] = photoState();
    const sequence = state.beginAnalysis(photo);
    state.acceptStatus(photo, sequence, 'run-1', succeeded([]));

    assert.equal(state.addSuggestion(photo), false);
    assert.equal(photo.message.includes('inspection visuelle'), true);
});

test('un échec laisse le formulaire manuel utilisable', () => {
    const [state, photo] = photoState('Contrôle manuel conservé.');
    const sequence = state.beginAnalysis(photo);
    state.fail(photo, sequence);

    assert.equal(photo.phase, 'failed');
    assert.equal(state.notes, 'Contrôle manuel conservé.');
});

test('un résultat invalide ou technique est remplacé par un message client sûr', () => {
    const [state, photo] = photoState();
    const sequence = state.beginAnalysis(photo);

    state.acceptStatus(photo, sequence, 'run-1', {
        ...succeeded([]),
        message: 'runtime exception traceback',
    });

    assert.equal(photo.message.includes('runtime'), false);
    assert.equal(photo.message.includes('inspection visuelle'), true);
});

test('une classe ou une bounding box non autorisée est refusée', () => {
    const [state, photo] = photoState();
    const sequence = state.beginAnalysis(photo);
    const payload = succeeded([{ type: 'unknown', label: 'Inconnu', confidence: 0.8, box: { x: -1, y: 0, width: 1, height: 1 } }]);

    assert.equal(state.acceptStatus(photo, sequence, 'run-1', payload), true);
    assert.equal(photo.phase, 'failed');
    assert.equal(photo.detections.length, 0);
});

test('le polling s’arrête au timeout sans toucher aux observations', async () => {
    const assistant = createReturnDamageAssistant({
        initialNotes: 'Inspection manuelle.',
        ready: true,
        storeUrl: '/store',
        maxPollAttempts: 0,
        fetchRequest: async () => { throw new Error('ne doit pas être appelée'); },
        schedule: () => {},
    });
    assistant.addFiles([{ name: 'retour.jpg' }]);
    const photo = assistant.photos[0];
    const sequence = assistant.beginAnalysis(photo);

    await assistant.poll(photo, sequence, 'run-1', '/status', 0);

    assert.equal(photo.phase, 'failed');
    assert.equal(assistant.notes, 'Inspection manuelle.');
});

test('retirer une photo annule logiquement son polling et son rattachement', () => {
    const [state, photo] = photoState();
    const sequence = state.beginAnalysis(photo);
    state.acceptStatus(photo, sequence, 'run-1', succeeded());
    state.addSuggestion(photo);

    state.removePhoto(photo);

    assert.equal(state.photos.length, 0);
    assert.deepEqual(state.selectedRunIds, []);
    assert.equal(state.acceptStatus(photo, sequence, 'run-1', succeeded()), false);
});
