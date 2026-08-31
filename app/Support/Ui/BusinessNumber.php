<?php

namespace App\Support\Ui;

use App\Support\Intelligence\DemandForecasting\DemandForecastPlanningUnits;
use InvalidArgumentException;

final class BusinessNumber
{
    public const UNAVAILABLE = 'Indisponible';

    private const THIN_SPACE = "\u{202F}";

    public static function integer(string|int|float|null $value): string
    {
        $normalized = self::normalize($value);
        if ($normalized === null) {
            return self::UNAVAILABLE;
        }

        [$whole, $fraction] = array_pad(explode('.', ltrim($normalized, '-'), 2), 2, '');
        if ($fraction !== '' && trim($fraction, '0') !== '') {
            return self::UNAVAILABLE;
        }

        $sign = str_starts_with($normalized, '-') && trim($whole, '0') !== '' ? '-' : '';

        return $sign.self::group($whole);
    }

    public static function count(
        string|int|float|null $value,
        string $singular,
        ?string $plural = null,
    ): string {
        $normalized = self::normalize($value);
        if ($normalized === null || str_starts_with($normalized, '-')) {
            return self::UNAVAILABLE;
        }

        $formatted = self::integer($value);
        if ($formatted === self::UNAVAILABLE) {
            return $formatted;
        }

        $unit = in_array($formatted, ['0', '1'], true) ? $singular : ($plural ?? $singular.'s');

        return $formatted.' '.$unit;
    }

    public static function planningVehicles(string|int|float|null $conditionalMean): string
    {
        if ($conditionalMean === null) {
            return self::UNAVAILABLE;
        }

        try {
            $value = (new DemandForecastPlanningUnits)->convert($conditionalMean);
        } catch (InvalidArgumentException) {
            return self::UNAVAILABLE;
        }

        return self::count($value->planningVehicleUnits, 'véhicule');
    }

    public static function percentage(
        string|int|float|null $value,
        int $maximumDecimals = 1,
    ): string {
        $normalized = self::normalize($value);
        if ($normalized === null || ! self::between($normalized, '0', '100')) {
            return self::UNAVAILABLE;
        }

        return self::decimal($normalized, 0, max(0, min(2, $maximumDecimals))).' %';
    }

    public static function ratioPercentage(
        string|int|float|null $value,
        string|int|float|null $total,
        int $maximumDecimals = 2,
    ): string {
        $normalizedValue = self::normalize($value);
        $normalizedTotal = self::normalize($total);
        if ($normalizedValue === null
            || $normalizedTotal === null
            || str_starts_with($normalizedValue, '-')
            || str_starts_with($normalizedTotal, '-')) {
            return self::UNAVAILABLE;
        }

        $numericValue = (float) $normalizedValue;
        $numericTotal = (float) $normalizedTotal;
        if (! is_finite($numericValue)
            || ! is_finite($numericTotal)
            || $numericTotal <= 0
            || $numericValue > $numericTotal) {
            return self::UNAVAILABLE;
        }

        return self::percentage(($numericValue / $numericTotal) * 100, $maximumDecimals);
    }

    /**
     * @return array{value: string, max: string, percentage: string, width: string}|null
     */
    public static function progress(
        string|int|float|null $value,
        string|int|float|null $maximum,
        int $maximumDecimals = 1,
    ): ?array {
        $normalizedValue = self::normalize($value);
        $normalizedMaximum = self::normalize($maximum);
        if ($normalizedValue === null || $normalizedMaximum === null) {
            return null;
        }

        $numericValue = (float) $normalizedValue;
        $numericMaximum = (float) $normalizedMaximum;
        if (! is_finite($numericValue) || ! is_finite($numericMaximum) || $numericMaximum <= 0) {
            return null;
        }

        $boundedValue = min(max($numericValue, 0), $numericMaximum);
        $percentageValue = ($boundedValue / $numericMaximum) * 100;
        $bounded = self::normalize($boundedValue);
        $percentage = self::percentage($percentageValue, $maximumDecimals);
        if ($bounded === null || $percentage === self::UNAVAILABLE) {
            return null;
        }

        return [
            'value' => $bounded,
            'max' => $normalizedMaximum,
            'percentage' => $percentage,
            'width' => str_replace([self::THIN_SPACE, ',', ' %'], ['', '.', ''], $percentage),
        ];
    }

    public static function average(
        string|int|float|null $value,
        int $maximumDecimals = 2,
        int $minimumDecimals = 0,
    ): string {
        $normalized = self::normalize($value);
        if ($normalized === null) {
            return self::UNAVAILABLE;
        }

        $maximumDecimals = max(0, min(3, $maximumDecimals));
        $minimumDecimals = max(0, min($maximumDecimals, $minimumDecimals));

        return self::decimal($normalized, $minimumDecimals, $maximumDecimals);
    }

    public static function confidence(string|int|float|null $ratio): string
    {
        $normalized = self::normalize($ratio);
        if ($normalized === null || ! self::between($normalized, '0', '1')) {
            return self::UNAVAILABLE;
        }

        return self::decimal(self::multiplyByHundred($normalized), 2, 2).' %';
    }

    public static function complementConfidence(string|int|float|null $ratio): string
    {
        $normalized = self::normalize($ratio);
        if ($normalized === null || ! self::between($normalized, '0', '1')) {
            return self::UNAVAILABLE;
        }

        return self::confidence(self::complementRatio($normalized));
    }

    public static function scientificDecimal(
        string|int|float|null $value,
        int $maximumDecimals = 4,
        int $minimumDecimals = 0,
    ): string {
        $normalized = self::normalize($value);
        if ($normalized === null) {
            return self::UNAVAILABLE;
        }

        $maximumDecimals = max(0, min(4, $maximumDecimals));
        $minimumDecimals = max(0, min($maximumDecimals, $minimumDecimals));

        return self::decimal($normalized, $minimumDecimals, $maximumDecimals);
    }

    public static function signedScientificDecimal(
        string|int|float|null $value,
        int $maximumDecimals = 2,
    ): string {
        $normalized = self::normalize($value);
        if ($normalized === null) {
            return self::UNAVAILABLE;
        }

        $formatted = self::scientificDecimal($normalized, $maximumDecimals);
        if (! str_starts_with($normalized, '-') && ! self::isZero($normalized)) {
            return '+'.$formatted;
        }

        return $formatted;
    }

    public static function money(string|int|null $amount, string $currency): string
    {
        $normalized = self::normalize($amount);
        if ($normalized === null
            || preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $normalized) !== 1
            || preg_match('/^[A-Z]{3}$/D', strtoupper($currency)) !== 1) {
            return self::UNAVAILABLE;
        }

        return self::decimal($normalized, 2, 2).' '.strtoupper($currency);
    }

    public static function distance(
        string|int|float|null $kilometres,
        int $maximumDecimals = 3,
    ): string {
        $normalized = self::normalize($kilometres);
        $maximumDecimals = max(0, min(3, $maximumDecimals));
        if ($normalized === null
            || str_starts_with($normalized, '-')
            || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,3})?$/D', $normalized) !== 1) {
            return self::UNAVAILABLE;
        }

        return self::decimal($normalized, 0, $maximumDecimals).' km';
    }

    private static function normalize(string|int|float|null $value): ?string
    {
        if ($value === null || (is_float($value) && ! is_finite($value))) {
            return null;
        }

        $normalized = is_float($value)
            ? rtrim(rtrim(sprintf('%.12F', $value), '0'), '.')
            : trim((string) $value);

        return preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $normalized) === 1
            ? $normalized
            : null;
    }

    private static function decimal(string $normalized, int $minimumDecimals, int $maximumDecimals): string
    {
        $negative = str_starts_with($normalized, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($normalized, '-'), 2), 2, '');
        if (strlen($fraction) > $maximumDecimals) {
            $roundUp = (int) $fraction[$maximumDecimals] >= 5;
            $fraction = substr($fraction, 0, $maximumDecimals);
            if ($roundUp) {
                [$whole, $fraction] = self::increment($whole, $fraction);
            }
        }

        $fraction = rtrim($fraction, '0');
        $fraction = str_pad($fraction, $minimumDecimals, '0');
        $isZero = trim($whole, '0') === '' && trim($fraction, '0') === '';

        return ($negative && ! $isZero ? '-' : '')
            .self::group($whole)
            .($fraction === '' ? '' : ','.$fraction);
    }

    /** @return array{string, string} */
    private static function increment(string $whole, string $fraction): array
    {
        $digits = $whole.$fraction;
        $carry = 1;
        for ($position = strlen($digits) - 1; $position >= 0 && $carry === 1; $position--) {
            $next = ((int) $digits[$position]) + 1;
            $digits[$position] = (string) ($next % 10);
            $carry = intdiv($next, 10);
        }
        if ($carry === 1) {
            $digits = '1'.$digits;
        }

        $fractionLength = strlen($fraction);

        return $fractionLength === 0
            ? [$digits, '']
            : [substr($digits, 0, -$fractionLength) ?: '0', substr($digits, -$fractionLength)];
    }

    private static function group(string $whole): string
    {
        return (string) preg_replace(
            '/\B(?=(?:[0-9]{3})+(?![0-9]))/u',
            self::THIN_SPACE,
            ltrim($whole, '0') ?: '0',
        );
    }

    private static function between(string $value, string $minimum, string $maximum): bool
    {
        $numeric = (float) $value;

        return is_finite($numeric) && $numeric >= (float) $minimum && $numeric <= (float) $maximum;
    }

    private static function multiplyByHundred(string $normalized): string
    {
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $digits = $whole.str_pad($fraction, 2, '0');
        $scale = max(0, strlen($fraction) - 2);
        if ($scale === 0) {
            return ltrim($digits, '0') ?: '0';
        }

        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);

        return (ltrim(substr($digits, 0, -$scale), '0') ?: '0').'.'.substr($digits, -$scale);
    }

    private static function complementRatio(string $normalized): string
    {
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $fraction = rtrim($fraction, '0');
        if ($whole === '1') {
            return '0';
        }
        if ($fraction === '') {
            return '1';
        }

        $complement = implode('', array_map(
            static fn (string $digit): string => (string) (9 - (int) $digit),
            str_split($fraction),
        ));
        [, $complement] = self::increment('0', $complement);

        return '0.'.$complement;
    }

    private static function isZero(string $normalized): bool
    {
        return trim(str_replace(['-', '.'], '', $normalized), '0') === '';
    }
}
