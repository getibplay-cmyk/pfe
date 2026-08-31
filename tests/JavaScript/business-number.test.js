import assert from 'node:assert/strict';
import test from 'node:test';

import {
    BUSINESS_VALUE_UNAVAILABLE,
    forecastPlanningUnits,
    formatAverage,
    formatBusinessInteger,
    formatComplementConfidence,
    formatConfidence,
    formatDistance,
    formatForecastPlanningVehicles,
    formatMoney,
    formatPercentage,
    formatRatioPercentage,
    formatScientificDecimal,
    formatSignedScientificDecimal,
    formatVehicleUnits,
} from '../../resources/js/business-number.js';

test('HGB decimals become the same integer planning unit used by every business surface', () => {
    const cases = [
        ['0', 0, '0 véhicule'],
        ['0.01', 1, '1 véhicule'],
        ['7', 7, '7 véhicules'],
        ['7.2', 8, '8 véhicules'],
        ['19.263290', 20, '20 véhicules'],
        ['-3.5', 0, '0 véhicule'],
    ];

    for (const [raw, units, label] of cases) {
        assert.equal(forecastPlanningUnits(raw), units);
        assert.equal(formatForecastPlanningVehicles(raw), label);
    }
});

test('NaN infinity and invalid HGB values are unavailable', () => {
    for (const value of [NaN, Infinity, -Infinity, 'NaN', 'INF', '1e2', 'invalid']) {
        assert.equal(forecastPlanningUnits(value), null);
        assert.equal(formatForecastPlanningVehicles(value), BUSINESS_VALUE_UNAVAILABLE);
    }
});

test('discrete counts use French integers and correct vehicle pluralisation', () => {
    assert.equal(formatBusinessInteger('19263.000'), '19 263');
    assert.equal(formatBusinessInteger('7.2'), BUSINESS_VALUE_UNAVAILABLE);
    assert.equal(formatVehicleUnits(0), '0 véhicule');
    assert.equal(formatVehicleUnits(1), '1 véhicule');
    assert.equal(formatVehicleUnits(2), '2 véhicules');
    assert.equal(formatVehicleUnits(-1), BUSINESS_VALUE_UNAVAILABLE);
});

test('rates confidence money and distances keep justified decimals', () => {
    assert.equal(formatPercentage('33.35'), '33,4 %');
    assert.equal(formatRatioPercentage(1, 3), '33,33 %');
    assert.equal(formatRatioPercentage(1, 0), BUSINESS_VALUE_UNAVAILABLE);
    assert.equal(formatRatioPercentage(4, 3), BUSINESS_VALUE_UNAVAILABLE);
    assert.equal(formatAverage('7.200000'), '7,2');
    assert.equal(formatConfidence('0.84273'), '84,27 %');
    assert.equal(formatConfidence('0.5'), '50,00 %');
    assert.equal(formatComplementConfidence('0.152342'), '84,77 %');
    assert.equal(formatComplementConfidence('0'), '100,00 %');
    assert.equal(formatComplementConfidence('1.000000'), '0,00 %');
    assert.equal(formatScientificDecimal('0.842735'), '0,8427');
    assert.equal(formatScientificDecimal('0.025', 4, 4), '0,0250');
    assert.equal(formatSignedScientificDecimal('1.250000'), '+1,25');
    assert.equal(formatSignedScientificDecimal('-1.250000'), '-1,25');
    assert.equal(formatSignedScientificDecimal('0.000000'), '0');
    assert.equal(formatMoney('12345.5', 'mad'), '12 345,50 MAD');
    assert.equal(formatDistance('87.400'), '87,4 km');
    assert.equal(formatDistance('87.425'), '87,425 km');
    assert.equal(formatDistance('87.4259'), BUSINESS_VALUE_UNAVAILABLE);
});
