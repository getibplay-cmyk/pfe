import './bootstrap';

import Alpine from 'alpinejs';
import { createVehicleColorAssistant } from './vehicle-color-assistant';
import { createVehicleRegistrationAssistant } from './vehicle-registration-assistant';
import { createReturnDamageAssistant } from './return-damage-assistant';
import { createReservationDemandForecast } from './reservation-demand-forecast';
import { createFleetReallocationPlanning } from './fleet-reallocation-planning';
import { initializePlatformStatistics } from './platform-statistics';
import { initializeTenantStatistics } from './tenant-statistics';
import { initializeBelkhirSpaceLoading, initializeLoadingForms } from './form-enhancements';
import { registerBelkhirSpaceUi } from './belkhir-space-ui';

window.Alpine = Alpine;

document.addEventListener('alpine:init', () => {
    registerBelkhirSpaceUi(Alpine);

    Alpine.data('vehicleColorAssistant', (config) => createVehicleColorAssistant(config));
    Alpine.data('vehicleRegistrationAssistant', (config) => createVehicleRegistrationAssistant(config));
    Alpine.data('returnDamageAssistant', (config) => createReturnDamageAssistant(config));
    Alpine.data('reservationDemandForecast', (config) => createReservationDemandForecast(config));
    Alpine.data('fleetReallocationPlanning', (config) => createFleetReallocationPlanning(config));

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
    initializePlatformStatistics();
    initializeTenantStatistics();
    const belkhirSpaceLoading = initializeBelkhirSpaceLoading();
    initializeLoadingForms(document, window, belkhirSpaceLoading);

    const invalidField = document.querySelector('[aria-invalid="true"]');

    if (invalidField instanceof HTMLElement) {
        invalidField.focus({ preventScroll: true });
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        invalidField.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });
    }
});
