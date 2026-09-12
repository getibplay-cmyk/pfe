import './bootstrap';
import { initializeFleetPlanningDrag } from './fleet-planning-drag';
import { initializeAnnotationEditor } from './annotation-editor';
import { initializeGuidedInspection } from './guided-inspection';
import { initializeWorkspaceSearch } from './workspace-search';

import Alpine from 'alpinejs';
import { createAppShell } from './app-shell';
import { createVehicleColorAssistant } from './vehicle-color-assistant';
import { createVehicleRegistrationAssistant } from './vehicle-registration-assistant';
import { createReturnDamageAssistant } from './return-damage-assistant';
import { createReservationDemandForecast } from './reservation-demand-forecast';
import { createFleetReallocationPlanning } from './fleet-reallocation-planning';
import { initializePlatformStatistics } from './platform-statistics';
import { initializeTenantStatistics } from './tenant-statistics';
import { initializeBelkhirSpaceLoading, initializeLoadingForms } from './form-enhancements';
import { registerBelkhirSpaceUi } from './belkhir-space-ui';
import { initializeCmiCheckout } from './cmi-checkout';
import { initializeUnsavedForms } from './unsaved-form';

window.Alpine = Alpine;

document.addEventListener('alpine:init', () => {
    registerBelkhirSpaceUi(Alpine);

    Alpine.data('vehicleColorAssistant', (config) => createVehicleColorAssistant(config));
    Alpine.data('vehicleRegistrationAssistant', (config) => createVehicleRegistrationAssistant(config));
    Alpine.data('returnDamageAssistant', (config) => createReturnDamageAssistant(config));
    Alpine.data('reservationDemandForecast', (config) => createReservationDemandForecast(config));
    Alpine.data('fleetReallocationPlanning', (config) => createFleetReallocationPlanning(config));

    Alpine.data('appShell', () => createAppShell());
});

Alpine.start();

document.addEventListener('DOMContentLoaded', () => {
    initializeFleetPlanningDrag();
    initializeAnnotationEditor();
    initializeGuidedInspection();
    initializeWorkspaceSearch();
    initializePlatformStatistics();
    initializeTenantStatistics();
    const belkhirSpaceLoading = initializeBelkhirSpaceLoading();
    initializeLoadingForms(document, window, belkhirSpaceLoading);
    initializeCmiCheckout();
    initializeUnsavedForms();

    const invalidField = document.querySelector('[aria-invalid="true"]');

    if (invalidField instanceof HTMLElement) {
        invalidField.focus({ preventScroll: true });
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        invalidField.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });
    }
});
