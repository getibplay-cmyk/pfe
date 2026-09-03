import test from 'node:test';
import assert from 'node:assert/strict';

import { initializeCmiCheckout } from '../../resources/js/cmi-checkout.js';

test('le checkout CMI programme une seule soumission accessible', () => {
    let submitted = 0;
    let callback = null;
    const form = {
        dataset: {},
        requestSubmit() { submitted += 1; },
    };
    const root = { querySelector: () => form };
    const host = { setTimeout(next) { callback = next; } };

    assert.equal(initializeCmiCheckout(root, host), true);
    assert.equal(initializeCmiCheckout(root, host), false);
    assert.equal(submitted, 0);
    callback();
    assert.equal(submitted, 1);
});

test('le checkout CMI respecte le mode manuel et une page sans formulaire', () => {
    const manual = { dataset: { autoSubmit: 'false' } };

    assert.equal(initializeCmiCheckout({ querySelector: () => manual }, {}), false);
    assert.equal(initializeCmiCheckout({ querySelector: () => null }, {}), false);
});
