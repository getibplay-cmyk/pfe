import test from 'node:test';
import assert from 'node:assert/strict';
import { createUnsavedState } from '../../resources/js/unsaved-form.js';

test('a pristine form does not warn on navigation', () => assert.equal(createUnsavedState().dirty, false));
test('edits warn until a successful submission', () => {
    const state = createUnsavedState(); state.change(); assert.equal(state.dirty, true);
    state.submit(); assert.equal(state.dirty, false);
});
test('a cancelled submission preserves the warning', () => {
    const state = createUnsavedState(); state.change(); state.submit(true); assert.equal(state.dirty, true);
});
test('resetting explicitly discards pending changes', () => {
    const state = createUnsavedState(); state.change(); state.reset(); assert.equal(state.dirty, false);
});
