import { t } from './i18n.js';
export function normalizedBox(start, end) {
    const clamp = (value) => Math.min(1, Math.max(0, value));
    const x = Math.min(clamp(start.x), clamp(end.x));
    const y = Math.min(clamp(start.y), clamp(end.y));
    const w = Math.abs(clamp(start.x) - clamp(end.x));
    const h = Math.abs(clamp(start.y) - clamp(end.y));
    return w >= .005 && h >= .005 ? { x, y, w, h } : null;
}

export function initializeAnnotationEditor(root = document) {
    const form = root.querySelector('[data-annotation-editor]');
    if (!form) return;
    const input = form.querySelector('[data-annotation-boxes]');
    const svg = form.querySelector('[data-annotation-overlay]');
    const list = form.querySelector('[data-annotation-list]');
    const add = form.querySelector('[data-annotation-add]');
    let boxes;
    try { boxes = JSON.parse(input.value); } catch { boxes = []; }
    if (!Array.isArray(boxes)) boxes = [];
    let start;
    const draw = (preview) => {
        svg.replaceChildren();
        [...boxes, ...(preview ? [preview] : [])].forEach((box) => {
            const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            for (const [attribute, value] of Object.entries({ x: box.x * 1000, y: box.y * 1000, width: box.w * 1000, height: box.h * 1000, fill: 'rgba(249,115,22,.18)', stroke: '#f97316', 'stroke-width': 3, 'vector-effect': 'non-scaling-stroke' })) rect.setAttribute(attribute, String(value));
            svg.append(rect);
        });
    };
    const persist = () => { input.value = JSON.stringify(boxes); draw(); };
    const rebuild = () => {
        list.replaceChildren();
        boxes.forEach((box, index) => {
            const row = document.createElement('fieldset');
            row.className = 'grid grid-cols-2 gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-5';
            const legend = document.createElement('legend');
            legend.textContent = `${form.dataset.boxLabel} ${index + 1}`;
            row.append(legend);
            for (const key of ['x', 'y', 'w', 'h']) {
                const label = document.createElement('label');
                label.className = 'text-xs';
                label.textContent = `${t({ x: 'Position horizontale', y: 'Position verticale', w: 'Largeur', h: 'Hauteur' }[key])} (%)`;
                const field = document.createElement('input');
                field.type = 'number'; field.min = key === 'w' || key === 'h' ? '.1' : '0'; field.max = '100'; field.step = '.1';
                field.value = String(Math.round(box[key] * 1000) / 10); field.disabled = !add;
                field.className = 'block w-full rounded border-slate-300 text-sm';
                field.addEventListener('input', () => {
                    const value = Number(field.value) / 100;
                    if (Number.isFinite(value)) boxes[index][key] = value;
                    persist();
                });
                label.append(field); row.append(label);
            }
            if (add) {
                const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'rf-button-link'; remove.textContent = form.dataset.removeLabel;
                remove.addEventListener('click', () => { boxes.splice(index, 1); persist(); rebuild(); }); row.append(remove);
            }
            list.append(row);
        });
        if (add) add.disabled = boxes.length >= 100;
    };
    const point = (event) => { const bounds = svg.getBoundingClientRect(); return { x: (event.clientX - bounds.left) / bounds.width, y: (event.clientY - bounds.top) / bounds.height }; };
    if (add) {
        add.addEventListener('click', () => { if (boxes.length < 100) { boxes.push({ x: .1, y: .1, w: .2, h: .2 }); persist(); rebuild(); } });
        svg.addEventListener('pointerdown', (event) => { if (event.isPrimary && event.button === 0 && boxes.length < 100) { start = point(event); svg.setPointerCapture(event.pointerId); event.preventDefault(); } });
        svg.addEventListener('pointermove', (event) => { if (start) draw(normalizedBox(start, point(event))); });
        svg.addEventListener('pointerup', (event) => { if (!start) return; const box = normalizedBox(start, point(event)); start = null; if (box) boxes.push(box); persist(); rebuild(); });
        svg.addEventListener('pointercancel', () => { start = null; draw(); });
    }
    persist(); rebuild();
}
