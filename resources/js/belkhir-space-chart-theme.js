import { BELKHIR_SPACE_COLORS } from './belkhir-space-tokens.js';
import { formatBusinessInteger } from './business-number.js';

const FALLBACK_COLORS = Object.freeze({
    blue: BELKHIR_SPACE_COLORS.blue,
    orange: BELKHIR_SPACE_COLORS.orange,
    success: BELKHIR_SPACE_COLORS.success,
    warning: BELKHIR_SPACE_COLORS.warning,
    danger: BELKHIR_SPACE_COLORS.danger,
    info: BELKHIR_SPACE_COLORS.info,
    ink: BELKHIR_SPACE_COLORS.ink,
    muted: BELKHIR_SPACE_COLORS.muted,
    border: BELKHIR_SPACE_COLORS.border,
    surface: BELKHIR_SPACE_COLORS.surface,
});

export function belkhirSpaceChartColors(element = null) {
    if (typeof window === 'undefined' || typeof window.getComputedStyle !== 'function') {
        return FALLBACK_COLORS;
    }

    const source = element ?? document.documentElement;
    const styles = window.getComputedStyle(source);
    const read = (name, fallback) => styles.getPropertyValue(name).trim() || fallback;

    return Object.freeze({
        blue: read('--belkhir-space-blue', FALLBACK_COLORS.blue),
        orange: read('--belkhir-space-orange', FALLBACK_COLORS.orange),
        success: read('--belkhir-space-success', FALLBACK_COLORS.success),
        warning: read('--belkhir-space-warning', FALLBACK_COLORS.warning),
        danger: read('--belkhir-space-danger', FALLBACK_COLORS.danger),
        info: read('--belkhir-space-info', FALLBACK_COLORS.info),
        ink: read('--belkhir-space-ink', FALLBACK_COLORS.ink),
        muted: read('--belkhir-space-muted', FALLBACK_COLORS.muted),
        border: read('--belkhir-space-border', FALLBACK_COLORS.border),
        surface: read('--belkhir-space-surface', FALLBACK_COLORS.surface),
    });
}

export function belkhirSpaceCartesianScales({ integer = false, horizontal = false, maximum = null } = {}) {
    const colors = belkhirSpaceChartColors();
    const valueAxis = {
        beginAtZero: true,
        grid: { color: colors.border },
        ticks: {
            color: colors.muted,
            ...(integer ? { precision: 0, callback: (value) => formatBusinessInteger(value) } : {}),
        },
        border: { display: false },
        ...(maximum !== null ? { max: maximum } : {}),
    };
    const categoryAxis = {
        grid: { display: false },
        ticks: { color: colors.muted },
        border: { color: colors.border },
    };

    return horizontal
        ? { x: valueAxis, y: categoryAxis }
        : { x: categoryAxis, y: valueAxis };
}
