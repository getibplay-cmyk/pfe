import { SANAD_PILOT_COLORS } from './sanad-pilot-tokens.js';
import { formatBusinessInteger } from './business-number.js';

const FALLBACK_COLORS = Object.freeze({
    blue: SANAD_PILOT_COLORS.blue,
    orange: SANAD_PILOT_COLORS.orange,
    success: SANAD_PILOT_COLORS.success,
    warning: SANAD_PILOT_COLORS.warning,
    danger: SANAD_PILOT_COLORS.danger,
    info: SANAD_PILOT_COLORS.info,
    ink: SANAD_PILOT_COLORS.ink,
    muted: SANAD_PILOT_COLORS.muted,
    border: SANAD_PILOT_COLORS.border,
    surface: SANAD_PILOT_COLORS.surface,
});

export function sanadPilotChartColors(element = null) {
    if (typeof window === 'undefined' || typeof window.getComputedStyle !== 'function') {
        return FALLBACK_COLORS;
    }

    const source = element ?? document.documentElement;
    const styles = window.getComputedStyle(source);
    const read = (name, fallback) => styles.getPropertyValue(name).trim() || fallback;

    return Object.freeze({
        blue: read('--sanad-pilot-blue', FALLBACK_COLORS.blue),
        orange: read('--sanad-pilot-orange', FALLBACK_COLORS.orange),
        success: read('--sanad-pilot-success', FALLBACK_COLORS.success),
        warning: read('--sanad-pilot-warning', FALLBACK_COLORS.warning),
        danger: read('--sanad-pilot-danger', FALLBACK_COLORS.danger),
        info: read('--sanad-pilot-info', FALLBACK_COLORS.info),
        ink: read('--sanad-pilot-ink', FALLBACK_COLORS.ink),
        muted: read('--sanad-pilot-muted', FALLBACK_COLORS.muted),
        border: read('--sanad-pilot-border', FALLBACK_COLORS.border),
        surface: read('--sanad-pilot-surface', FALLBACK_COLORS.surface),
    });
}

export function sanadPilotCartesianScales({ integer = false, horizontal = false, maximum = null } = {}) {
    const colors = sanadPilotChartColors();
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
