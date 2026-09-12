import { arabicMessages } from './arabic-messages.js';

export function currentLocale() {
    return typeof document !== 'undefined' && document.documentElement?.lang === 'ar' ? 'ar-MA' : 'fr-FR';
}

export function t(message, replacements = {}, locale = currentLocale()) {
    const key = String(message ?? '');
    let translated = locale.startsWith('ar') && Object.hasOwn(arabicMessages, key) ? arabicMessages[key] : key;
    for (const [name, value] of Object.entries(replacements)) translated = translated.replaceAll(`:${name}`, String(value));
    return translated;
}
