// Only state is retained: customer values never enter browser storage.
export function createUnsavedState() {
    let dirty = false;
    return {
        change() { dirty = true; },
        reset() { dirty = false; },
        submit(prevented = false) { if (!prevented) dirty = false; },
        get dirty() { return dirty; },
    };
}

export function initializeUnsavedForms(root = document, host = window) {
    const states = [];
    for (const form of root.querySelectorAll('form[data-unsaved-warning]')) {
        const state = createUnsavedState();
        states.push(state);
        form.addEventListener('input', () => state.change());
        form.addEventListener('change', () => state.change());
        form.addEventListener('reset', () => state.reset());
        form.addEventListener('submit', (event) => host.queueMicrotask(() => state.submit(event.defaultPrevented)));
    }
    host.addEventListener('beforeunload', (event) => {
        if (!states.some((state) => state.dirty)) return;
        host.dispatchEvent(new Event('belkhir-space:loading-cancel'));
        event.preventDefault();
        event.returnValue = '';
    });
}
