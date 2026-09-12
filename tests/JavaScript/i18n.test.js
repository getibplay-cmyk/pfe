import test from 'node:test';
import assert from 'node:assert/strict';
import { currentLocale, t } from '../../resources/js/i18n.js';

test('Arabic messages preserve interpolation and keep unknown input as text', () => {
    assert.equal(t('Photo :value1', { value1: 3 }, 'ar-MA'), 'الصورة 3');
    assert.equal(t('Nom original', {}, 'ar'), 'Nom original');
    assert.equal(t('constructor', {}, 'ar'), 'constructor');
    assert.equal(t('__proto__', {}, 'ar'), '__proto__');
    assert.equal(t('Photo :value1', { value1: '<img>' }, 'ar'), 'الصورة <img>');
});

test('French remains the default and document language selects Arabic', () => {
    assert.equal(currentLocale(), 'fr-FR');
    assert.equal(t('Photo :value1', { value1: 2 }), 'Photo 2');
    const before = globalThis.document;
    try {
        globalThis.document = { documentElement: { lang: 'ar' } };
        assert.equal(currentLocale(), 'ar-MA');
        assert.equal(t('Confirmer'), 'تأكيد');
    } finally {
        if (before === undefined) delete globalThis.document;
        else globalThis.document = before;
    }
});
