export const BUSINESS_VALUE_UNAVAILABLE = 'Indisponible';

const INTEGER_FORMAT = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });

export function forecastPlanningUnits(conditionalMean) {
    const normalized = normalizeDecimal(conditionalMean);
    if (normalized === null) return null;
    if (normalized.startsWith('-')) return 0;

    const [whole, fraction = ''] = normalized.split('.');
    const units = Number(whole) + (/[^0]/u.test(fraction) ? 1 : 0);

    return Number.isSafeInteger(units) ? units : null;
}

export function formatBusinessInteger(value) {
    const normalized = normalizeDecimal(value);
    if (normalized === null) return BUSINESS_VALUE_UNAVAILABLE;
    const [whole, fraction = ''] = normalized.replace(/^-/, '').split('.');
    if (/[^0]/u.test(fraction)) return BUSINESS_VALUE_UNAVAILABLE;

    const integer = Number(`${normalized.startsWith('-') ? '-' : ''}${whole}`);

    return Number.isSafeInteger(integer) ? INTEGER_FORMAT.format(integer) : BUSINESS_VALUE_UNAVAILABLE;
}

export function formatVehicleUnits(value) {
    const normalized = normalizeDecimal(value);
    if (normalized === null || normalized.startsWith('-')) return BUSINESS_VALUE_UNAVAILABLE;

    const formatted = formatBusinessInteger(value);
    if (formatted === BUSINESS_VALUE_UNAVAILABLE) return formatted;

    return `${formatted} ${[0, 1].includes(Number(value)) ? 'véhicule' : 'véhicules'}`;
}

export function formatForecastPlanningVehicles(conditionalMean) {
    const units = forecastPlanningUnits(conditionalMean);

    return units === null ? BUSINESS_VALUE_UNAVAILABLE : formatVehicleUnits(units);
}

export function formatPercentage(value, maximumFractionDigits = 1) {
    return formatBoundedPercent(value, maximumFractionDigits, false);
}

export function formatRatioPercentage(value, total, maximumFractionDigits = 2) {
    const normalizedValue = normalizeDecimal(value);
    const normalizedTotal = normalizeDecimal(total);
    if (normalizedValue === null
        || normalizedTotal === null
        || normalizedValue.startsWith('-')
        || normalizedTotal.startsWith('-')) {
        return BUSINESS_VALUE_UNAVAILABLE;
    }

    const numericValue = Number(normalizedValue);
    const numericTotal = Number(normalizedTotal);
    if (! Number.isFinite(numericValue)
        || ! Number.isFinite(numericTotal)
        || numericTotal <= 0
        || numericValue > numericTotal) {
        return BUSINESS_VALUE_UNAVAILABLE;
    }

    return formatPercentage((numericValue / numericTotal) * 100, maximumFractionDigits);
}

export function formatAverage(value, maximumFractionDigits = 2) {
    const normalized = normalizeDecimal(value);
    if (normalized === null) return BUSINESS_VALUE_UNAVAILABLE;

    return formatDecimal(normalized, 0, Math.max(0, Math.min(3, maximumFractionDigits)));
}

export function formatConfidence(value) {
    const normalized = normalizeDecimal(value);
    if (normalized === null) return BUSINESS_VALUE_UNAVAILABLE;
    const numeric = Number(normalized);
    if (! Number.isFinite(numeric) || numeric < 0 || numeric > 1) return BUSINESS_VALUE_UNAVAILABLE;

    return `${formatDecimal(multiplyByHundred(normalized), 2, 2)} %`;
}

export function formatComplementConfidence(value) {
    const normalized = normalizeDecimal(value);
    if (normalized === null) return BUSINESS_VALUE_UNAVAILABLE;
    const numeric = Number(normalized);
    if (! Number.isFinite(numeric) || numeric < 0 || numeric > 1) return BUSINESS_VALUE_UNAVAILABLE;

    return formatConfidence(complementRatio(normalized));
}

export function formatScientificDecimal(value, maximumFractionDigits = 4, minimumFractionDigits = 0) {
    const normalized = normalizeDecimal(value);
    if (normalized === null) return BUSINESS_VALUE_UNAVAILABLE;
    const maximum = Math.max(0, Math.min(4, maximumFractionDigits));
    const minimum = Math.max(0, Math.min(maximum, minimumFractionDigits));

    return formatDecimal(normalized, minimum, maximum);
}

export function formatSignedScientificDecimal(value, maximumFractionDigits = 2) {
    const normalized = normalizeDecimal(value);
    if (normalized === null) return BUSINESS_VALUE_UNAVAILABLE;
    const formatted = formatScientificDecimal(normalized, maximumFractionDigits);
    const digits = normalized.replace(/[.\-]/gu, '').replace(/0/gu, '');

    return ! normalized.startsWith('-') && digits !== '' ? `+${formatted}` : formatted;
}

export function formatMoney(value, currency) {
    const normalized = normalizeDecimal(value);
    if (normalized === null
        || ! /^-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/u.test(normalized)
        || ! /^[A-Z]{3}$/u.test(String(currency).toUpperCase())) {
        return BUSINESS_VALUE_UNAVAILABLE;
    }

    return `${formatDecimal(normalized, 2, 2)} ${String(currency).toUpperCase()}`;
}

export function formatDistance(value, maximumFractionDigits = 3) {
    const normalized = normalizeDecimal(value);
    if (normalized === null
        || normalized.startsWith('-')
        || ! /^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,3})?$/u.test(normalized)) {
        return BUSINESS_VALUE_UNAVAILABLE;
    }

    return `${formatDecimal(normalized, 0, Math.max(0, Math.min(3, maximumFractionDigits)))} km`;
}

function formatBoundedPercent(value, maximumFractionDigits, ratio) {
    const normalized = normalizeDecimal(value);
    if (normalized === null) return BUSINESS_VALUE_UNAVAILABLE;
    const numeric = Number(normalized);
    const maximum = ratio ? 1 : 100;
    if (! Number.isFinite(numeric) || numeric < 0 || numeric > maximum) return BUSINESS_VALUE_UNAVAILABLE;

    const percent = ratio ? multiplyByHundred(normalized) : normalized;

    return `${formatDecimal(percent, 0, Math.max(0, Math.min(2, maximumFractionDigits)))} %`;
}

function normalizeDecimal(value) {
    if (value === null || value === undefined) return null;
    if (typeof value === 'number' && ! Number.isFinite(value)) return null;
    const normalized = String(value).trim();

    return /^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/u.test(normalized) ? normalized : null;
}

function formatDecimal(normalized, minimumDecimals, maximumDecimals) {
    const numeric = Number(normalized);
    if (! Number.isFinite(numeric)) return BUSINESS_VALUE_UNAVAILABLE;

    return new Intl.NumberFormat('fr-FR', {
        minimumFractionDigits: minimumDecimals,
        maximumFractionDigits: maximumDecimals,
    }).format(numeric);
}

function multiplyByHundred(normalized) {
    const [whole, fraction = ''] = normalized.split('.');
    const digits = `${whole}${fraction.padEnd(2, '0')}`;
    const scale = Math.max(0, fraction.length - 2);
    if (scale === 0) return String(Number(digits));
    const padded = digits.padStart(scale + 1, '0');

    return `${Number(padded.slice(0, -scale) || '0')}.${padded.slice(-scale)}`;
}

function complementRatio(normalized) {
    const [whole, rawFraction = ''] = normalized.split('.');
    const fraction = rawFraction.replace(/0+$/u, '');
    if (whole === '1') return '0';
    if (fraction === '') return '1';

    const base = [...fraction].map((digit) => String(9 - Number(digit)));
    let carry = 1;
    for (let index = base.length - 1; index >= 0 && carry === 1; index -= 1) {
        const next = Number(base[index]) + carry;
        base[index] = String(next % 10);
        carry = Math.floor(next / 10);
    }

    return `0.${base.join('')}`;
}
