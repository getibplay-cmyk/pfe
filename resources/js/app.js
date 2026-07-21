import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

document.addEventListener('alpine:init', () => {
    Alpine.data('appShell', () => ({
        mobileMenu: false,
        menuTrigger: null,
        openMenu(trigger) {
            this.menuTrigger = trigger;
            this.mobileMenu = true;
            this.$nextTick(() => this.$refs.mobilePanel?.querySelector('a, button')?.focus());
        },
        closeMenu() {
            this.mobileMenu = false;
            this.$nextTick(() => this.menuTrigger?.focus());
        },
        trapMenu(event) {
            if (! this.mobileMenu || event.key !== 'Tab') return;

            const focusable = [...this.$refs.mobilePanel.querySelectorAll('a, button:not([disabled]), input:not([disabled])')];
            const first = focusable[0];
            const last = focusable.at(-1);

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (! event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },
    }));
});

Alpine.start();

document.addEventListener('DOMContentLoaded', () => {
    const invalidField = document.querySelector('[aria-invalid="true"]');

    if (invalidField instanceof HTMLElement) {
        invalidField.focus({ preventScroll: true });
        invalidField.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});
