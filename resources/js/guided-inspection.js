export function inspectionPayload(formData) {
    const data = { conditions: {} };
    for (const key of ['mileage', 'fuel_level', 'notes', 'photo_omission_reason']) data[key] = formData.get(key) || null;
    for (const key of ['body', 'interior', 'tyres', 'equipment']) data.conditions[key] = formData.get(`conditions[${key}]`) || 'not_checked';
    data.confirmed = formData.get('confirmed') === '1';
    return data;
}

export function initializeGuidedInspection(root = document) {
    const guide = root.querySelector('[data-inspection-guide]');
    const form = guide?.querySelector('[data-guide-form]');
    if (!guide || !form || form.querySelector('fieldset')?.disabled) return;
    const status = guide.querySelector('[data-guide-status]');
    const errors = guide.querySelector('[data-guide-errors]');
    const csrf = form.querySelector('[name="_token"]').value;
    let revision = Number(form.querySelector('[name="revision"]').value);
    let dirty = false;
    let changes = 0;
    let timer;
    let queue = Promise.resolve();
    let conflict = false;
    let leaving = false;
    const setRevision = (value) => {
        revision = value;
        guide.querySelectorAll('[name="revision"]').forEach((input) => { input.value = String(value); });
    };
    const send = async (url, body) => {
        if (conflict) throw new Error('conflict');
        status.textContent = guide.dataset.saving;
        const multipart = body instanceof FormData;
        let response;
        try {
            response = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, ...(multipart ? {} : { 'Content-Type': 'application/json' }) }, body: multipart ? body : JSON.stringify(body) });
        } catch (error) {
            status.textContent = guide.dataset.offline;
            throw error;
        }
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            conflict = response.status === 409;
            status.textContent = conflict ? guide.dataset.conflict : guide.dataset.failed;
            errors.textContent = Object.values(data.errors || {}).flat().join(' ') || data.message || '';
            throw new Error('save_failed');
        }
        errors.textContent = '';
        if (Number.isInteger(data.revision)) setRevision(data.revision);
        status.textContent = guide.dataset.saved;
        return data;
    };
    const persist = async () => {
        if (!dirty) return;
        const snapshot = changes;
        await send(form.action, { ...inspectionPayload(new FormData(form)), revision });
        dirty = snapshot !== changes;
    };
    const enqueue = (operation) => {
        queue = queue.catch(() => {}).then(operation);
        // Errors remain visible; subsequent explicit retries keep the last acknowledged revision.
        queue.catch(() => {});
        return queue;
    };
    form.addEventListener('input', () => {
        dirty = true; changes++;
        clearTimeout(timer);
        timer = setTimeout(() => enqueue(persist), 700);
    });
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        clearTimeout(timer);
        const completion = event.submitter?.hasAttribute('data-guide-complete');
        if (!completion) { dirty = true; changes++; enqueue(persist); return; }
        const target = event.submitter.formAction;
        enqueue(async () => {
            const data = await send(target, { ...inspectionPayload(new FormData(form)), revision });
            leaving = true; dirty = false;
            window.location.assign(data.redirect);
        });
    });
    guide.querySelectorAll('[data-guide-photo-form]').forEach((photoForm) => {
        photoForm.addEventListener('submit', (event) => {
            event.preventDefault();
            const body = new FormData(photoForm);
            const button = photoForm.querySelector('button');
            button.disabled = true;
            enqueue(async () => {
                await persist();
                body.set('revision', String(revision));
                const data = await send(photoForm.action, body);
                const card = photoForm.closest('[data-photo-angle]');
                const img = card.querySelector('[data-guide-photo]');
                if (img && data.photo_url) { img.src = data.photo_url; img.hidden = false; }
                card.querySelector('[data-guide-photo-status]').textContent = guide.dataset.saved;
                photoForm.querySelector('[type="file"]').value = '';
            }).finally(() => { button.disabled = false; }).catch(() => {});
        });
    });
    window.addEventListener('online', () => { if (dirty && !conflict) enqueue(persist); });
    window.addEventListener('beforeunload', (event) => { if (dirty && !leaving) { event.preventDefault(); event.returnValue = ''; } });
}
