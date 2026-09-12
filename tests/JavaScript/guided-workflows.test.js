import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizedBox } from '../../resources/js/annotation-editor.js';
import { inspectionPayload } from '../../resources/js/guided-inspection.js';
import { calendarDayShift } from '../../resources/js/fleet-planning-drag.js';

test('dragging in either direction produces the same normalized damage box', () => {
    const a = { x: .2, y: .3 }; const b = { x: .8, y: .7 };
    assert.deepEqual(normalizedBox(a, b), normalizedBox(b, a));
    const box = normalizedBox({ x: -.1, y: -.2 }, { x: 1.4, y: 1.3 });
    assert.deepEqual(box, { x: 0, y: 0, w: 1, h: 1 });
});

test('a click or a near-zero rectangle cannot become a damage label', () => {
    assert.equal(normalizedBox({ x: .2, y: .2 }, { x: .2, y: .2 }), null);
    assert.equal(normalizedBox({ x: .2, y: .2 }, { x: .201, y: .8 }), null);
});

test('an incomplete inspection keeps unchecked items and does not include files or tenant input', () => {
    const form = new FormData(); form.set('mileage', '1234'); form.set('conditions[body]', 'damaged'); form.set('tenant_id', '42'); form.set('file', new Blob(['private']), 'photo.jpg');
    const payload = inspectionPayload(form);
    assert.equal(payload.conditions.body, 'damaged');
    assert.equal(payload.conditions.tyres, 'not_checked');
    assert.equal(payload.confirmed, false);
    assert.equal(payload.fuel_level, null);
    assert.equal('file' in payload, false);
    assert.equal('tenant_id' in payload, false);
});

test('planning drags preserve calendar-day offsets across month and DST boundaries', () => {
    assert.equal(calendarDayShift('2026-03-28', '2026-03-30'), 2);
    assert.equal(calendarDayShift('2026-04-01', '2026-03-31'), -1);
    assert.equal(calendarDayShift('2026-01-01', '2028-01-01'), null);
    assert.equal(calendarDayShift('invalid', '2026-03-30'), null);
});
