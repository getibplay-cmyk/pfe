import { ATLAS_COLORS } from './atlas-tokens.js';

const FALLBACK_COLORS = Object.freeze({
    blue: ATLAS_COLORS.blue,
    orange: ATLAS_COLORS.orange,
    muted: ATLAS_COLORS.muted,
    border: ATLAS_COLORS.border,
    surface: ATLAS_COLORS.surface,
});

export function atlasChartColors(element = null) {
    if (typeof window === 'undefined' || typeof window.getComputedStyle !== 'function') {
        return FALLBACK_COLORS;
    }

    const source = element ?? document.documentElement;
    const styles = window.getComputedStyle(source);
    const read = (name, fallback) => styles.getPropertyValue(name).trim() || fallback;

    return Object.freeze({
        blue: read('--atlas-blue', FALLBACK_COLORS.blue),
        orange: read('--atlas-orange', FALLBACK_COLORS.orange),
        muted: read('--atlas-muted', FALLBACK_COLORS.muted),
        border: read('--atlas-border', FALLBACK_COLORS.border),
        surface: read('--atlas-surface', FALLBACK_COLORS.surface),
    });
}

export function atlasCartesianScales({ integer = false } = {}) {
    const colors = atlasChartColors();

    return {
        x: {
            grid: { display: false },
            ticks: { color: colors.muted },
            border: { color: colors.border },
        },
        y: {
            beginAtZero: true,
            grid: { color: colors.border },
            ticks: { color: colors.muted, ...(integer ? { precision: 0 } : {}) },
            border: { display: false },
        },
    };
}
