import test from 'node:test';
import assert from 'node:assert/strict';

import {
    createBelkhirSpaceConfirmDialog,
    createBelkhirSpaceFileInput,
    createBelkhirSpaceLightbox,
    readableFileSize,
    registerBelkhirSpaceUi,
} from '../../resources/js/belkhir-space-ui.js';

test('la taille de fichier reste lisible sans modifier le fichier', () => {
    assert.equal(readableFileSize(0), '0 octet');
    assert.equal(readableFileSize(900), '900 octets');
    assert.match(readableFileSize(2048), /2[\s\u00a0]?Ko/u);
    assert.match(readableFileSize(2 * 1024 * 1024), /2[\s\u00a0]?Mo/u);
});

test('un nouvel aperçu révoque toujours l’ancienne Object URL', () => {
    const created = [];
    const revoked = [];
    const urlApi = {
        createObjectURL(file) {
            const url = `blob:${file.name}`;
            created.push(url);

            return url;
        },
        revokeObjectURL(url) {
            revoked.push(url);
        },
    };
    const state = createBelkhirSpaceFileInput({ previewImages: true, urlApi });

    state.select([{ name: 'avant.jpg', size: 1024, type: 'image/jpeg' }]);
    state.select([{ name: 'apres.png', size: 2048, type: 'image/png' }]);

    assert.deepEqual(created, ['blob:avant.jpg', 'blob:apres.png']);
    assert.deepEqual(revoked, ['blob:avant.jpg']);
    assert.equal(state.previewUrl, 'blob:apres.png');

    state.destroy();
    assert.deepEqual(revoked, ['blob:avant.jpg', 'blob:apres.png']);
});

test('un fichier non image ne génère pas de base64 ni d’aperçu', () => {
    let created = false;
    const state = createBelkhirSpaceFileInput({
        previewImages: true,
        urlApi: { createObjectURL: () => { created = true; } },
    });

    state.select([{ name: 'contrat.pdf', size: 4096, type: 'application/pdf' }]);

    assert.equal(created, false);
    assert.equal(state.previewUrl, '');
    assert.equal(state.fileName, 'contrat.pdf');
});

test('la galerie boucle et restaure le focus après fermeture', () => {
    let restored = false;
    let closeFocused = false;
    const state = createBelkhirSpaceLightbox([
        { src: '/private/photo-1', alt: 'Vue avant' },
        { src: '/private/photo-2', alt: 'Vue arrière' },
    ]);
    state.$nextTick = (callback) => callback();
    state.$refs = { close: { focus: () => { closeFocused = true; } } };

    state.show(0, { focus: () => { restored = true; } });
    assert.equal(state.open, true);
    assert.equal(closeFocused, true);
    assert.equal(state.current.alt, 'Vue avant');

    state.previous();
    assert.equal(state.current.alt, 'Vue arrière');
    state.next();
    assert.equal(state.current.alt, 'Vue avant');

    state.close();
    assert.equal(state.open, false);
    assert.equal(restored, true);
});

test('la confirmation réutilise le formulaire original sans changer sa requête', () => {
    let requestedWith = null;
    const form = {
        dataset: {},
        requestSubmit(submitter) {
            requestedWith = submitter;
        },
    };
    const submitter = { type: 'submit' };
    const state = createBelkhirSpaceConfirmDialog();
    state.$nextTick = (callback) => callback();
    state.$refs = { cancel: { focus: () => {} } };

    state.show({ detail: {
        form,
        submitter,
        title: 'Archiver le document',
        resource: 'Document sélectionné',
        consequence: 'Le document ne figurera plus dans les listes actives.',
        confirmLabel: 'Archiver',
    } });
    state.confirm();

    assert.equal(form.dataset.belkhirSpaceConfirmed, 'true');
    assert.equal(requestedWith, submitter);
    assert.equal(state.open, false);
});

test('le registre Alpine expose uniquement des contrôleurs explicites', () => {
    const dataNames = [];
    const directiveNames = [];
    const Alpine = {
        data: (name) => dataNames.push(name),
        directive: (name) => directiveNames.push(name),
    };

    registerBelkhirSpaceUi(Alpine);

    assert.deepEqual(dataNames, ['belkhirSpaceConfirmDialog', 'belkhirSpaceFileInput', 'belkhirSpaceLightbox']);
    assert.deepEqual(directiveNames, ['belkhir-space-confirm']);
});
