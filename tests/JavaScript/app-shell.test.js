import test from 'node:test';
import assert from 'node:assert/strict';
import { createAppShell } from '../../resources/js/app-shell.js';

function fixture() {
    const events = new Map();
    let viewportListener;
    let restored = 0;
    const first = { focus() { root.activeElement = this; }, getClientRects: () => [1] };
    const last = { focus() { root.activeElement = this; }, getClientRects: () => [1] };
    const root = { documentElement: { style: { overflow: 'auto' } }, activeElement: null };
    const host = {
        matchMedia: () => ({ matches: false, addEventListener: (_, fn) => { viewportListener = fn; }, removeEventListener() {} }),
        addEventListener: (name, fn) => events.set(name, fn), removeEventListener: (name) => events.delete(name),
    };
    const shell = createAppShell(root, host);
    shell.$nextTick = (fn) => fn();
    shell.$refs = { mobilePanel: { querySelector: () => first, querySelectorAll: () => [first, last] } };
    shell.init();
    return { shell, root, first, last, events, trigger: { focus: () => { restored += 1; } },
        resize: () => viewportListener({ matches: true }), restored: () => restored };
}

test('le menu mobile bloque le défilement et restaure le focus et le défilement précédents', () => {
    const f = fixture();
    f.shell.openMenu(f.trigger);
    assert.equal(f.root.documentElement.style.overflow, 'hidden');
    assert.equal(f.root.activeElement, f.first);
    f.shell.openMenu(f.trigger);
    f.shell.closeMenu();
    assert.equal(f.root.documentElement.style.overflow, 'auto');
    assert.equal(f.restored(), 1);
});

test('le focus reste dans le menu dans les deux sens', () => {
    const f = fixture();
    f.shell.openMenu(f.trigger);
    let prevented = 0;
    f.shell.trapMenu({ key: 'Tab', shiftKey: true, preventDefault: () => { prevented += 1; } });
    assert.equal(f.root.activeElement, f.last);
    f.shell.trapMenu({ key: 'Tab', shiftKey: false, preventDefault: () => { prevented += 1; } });
    assert.equal(f.root.activeElement, f.first);
    assert.equal(prevented, 2);
});

test('le retour au bureau et la sortie de page ne laissent pas le défilement bloqué', () => {
    const f = fixture();
    f.shell.openMenu(f.trigger);
    f.resize();
    assert.equal(f.shell.mobileMenu, false);
    assert.equal(f.restored(), 0);
    assert.equal(f.root.documentElement.style.overflow, 'auto');
    f.shell.openMenu(f.trigger);
    f.events.get('pagehide')();
    assert.equal(f.shell.mobileMenu, false);
    assert.equal(f.root.documentElement.style.overflow, 'auto');
    f.shell.destroy();
    assert.equal(f.events.size, 0);
});
