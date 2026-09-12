export function calendarDayShift(from, to) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(from) || !/^\d{4}-\d{2}-\d{2}$/.test(to)) return null;
    const days = (Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86400000;
    return Number.isInteger(days) && Math.abs(days) <= 365 ? days : null;
}

export function initializeFleetPlanningDrag(root = document) {
    const grid = root.querySelector('[data-fleet-planning]');
    const form = root.querySelector('[data-replan-drop-form]');
    if (!grid || !form) return;
    let source;
    grid.querySelectorAll('[data-replan-source]').forEach((item) => {
        item.draggable = true;
        item.addEventListener('dragstart', (event) => {
            source = item;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', item.dataset.replanSource);
        });
        item.addEventListener('dragend', () => { source = null; grid.querySelectorAll('[data-replan-target]').forEach((cell) => cell.classList.remove('ring-2', 'ring-orange-500', 'ring-inset')); });
    });
    grid.querySelectorAll('[data-replan-target]').forEach((cell) => {
        const allowed = () => source && cell.dataset.agency === source.dataset.agency;
        cell.addEventListener('dragover', (event) => { if (allowed()) { event.preventDefault(); event.dataTransfer.dropEffect = 'move'; cell.classList.add('ring-2', 'ring-orange-500', 'ring-inset'); } });
        cell.addEventListener('dragleave', () => cell.classList.remove('ring-2', 'ring-orange-500', 'ring-inset'));
        cell.addEventListener('drop', (event) => {
            if (!allowed()) return;
            event.preventDefault();
            const shift = calendarDayShift(source.dataset.day, cell.dataset.day);
            if (shift === null) return;
            form.action = source.dataset.previewUrl;
            form.querySelector('[name="vehicle_id"]').value = cell.dataset.replanTarget;
            form.querySelector('[name="shift_days"]').value = String(shift);
            form.requestSubmit();
        });
    });
}
