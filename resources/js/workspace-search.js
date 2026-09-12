export function initializeWorkspaceSearch(root = document) {
    const dialog = root.querySelector('[data-workspace-dialog]');
    const trigger = root.querySelector('[data-workspace-open]');
    if (!dialog || !trigger || typeof dialog.showModal !== 'function') return;
    const input = dialog.querySelector('[data-workspace-query]');
    const results = dialog.querySelector('[data-workspace-results]');
    const status = dialog.querySelector('[data-workspace-status]');
    let previousFocus;
    let controller;
    let timer;
    let revision = 0;
    dialog.setAttribute('aria-labelledby', 'workspace-dialog-title');
    const open = () => {
        if (dialog.open) return;
        previousFocus = document.activeElement;
        dialog.showModal();
        input.focus();
    };
    const close = () => dialog.close();
    trigger.addEventListener('click', (event) => { event.preventDefault(); open(); });
    dialog.querySelector('[data-workspace-close]').addEventListener('click', close);
    dialog.addEventListener('close', () => { controller?.abort(); clearTimeout(timer); revision++; previousFocus?.focus(); });
    root.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k' && !event.altKey) {
            event.preventDefault(); dialog.open ? close() : open();
        }
    });
    input.addEventListener('input', () => {
        clearTimeout(timer);
        controller?.abort();
        const current = ++revision;
        results.replaceChildren();
        status.textContent = '';
        const q = input.value.trim();
        if (q.length < 2) return;
        timer = setTimeout(async () => {
            controller = new AbortController();
            status.textContent = dialog.dataset.loading;
            try {
                const url = new URL(dialog.dataset.searchUrl, window.location.href);
                url.searchParams.set('q', q);
                const response = await fetch(url, { signal: controller.signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!response.ok) throw new Error('search_failed');
                const data = await response.json();
                if (current !== revision || !dialog.open) return;
                for (const result of data.results) {
                    const target = new URL(result.url, window.location.href);
                    if (target.origin !== window.location.origin) continue;
                    const li = document.createElement('li');
                    const link = document.createElement('a');
                    link.href = target.href;
                    link.className = 'block rounded-lg px-3 py-3 text-sm text-brand-700 hover:bg-brand-50 focus:bg-brand-50';
                    link.textContent = `${result.group} · ${result.label}`;
                    li.append(link); results.append(li);
                }
                status.textContent = results.childElementCount ? '' : dialog.dataset.empty;
            } catch (error) {
                if (error.name !== 'AbortError' && current === revision) status.textContent = dialog.dataset.error;
            }
        }, 220);
    });
}
