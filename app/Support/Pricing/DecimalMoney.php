<?php

namespace App\Support\Pricing;

use InvalidArgumentException;

class DecimalMoney
{
    public static function toMinorUnits(string|int $amount): int
    {
        $normalized = trim((string) $amount);
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $normalized, $matches)) {
            throw new InvalidArgumentException('Montant décimal invalide.');
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    public static function fromMinorUnits(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $absolute = abs($amount);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }

    public static function taxForExclusive(string|int $amount, string $rate): string
    {
        $minor = self::divideRounded(self::toMinorUnits($amount) * self::rateUnits($rate), 1_000_000);

        return self::fromMinorUnits($minor);
    }

    public static function taxForInclusive(string|int $amount, string $rate): string
    {
        $rateUnits = self::rateUnits($rate);
        if ($rateUnits === 0) {
            return '0.00';
        }

        $minor = self::divideRounded(self::toMinorUnits($amount) * $rateUnits, 1_000_000 + $rateUnits);

        return self::fromMinorUnits($minor);
    }

    private static function rateUnits(string $rate): int
    {
        $normalized = trim($rate);
        if (! preg_match('/^(\d{1,3})(?:\.(\d{1,4}))?$/', $normalized, $matches)) {
            throw new InvalidArgumentException('Taux décimal invalide.');
        }

        $units = ((int) $matches[1] * 10_000) + (int) str_pad($matches[2] ?? '', 4, '0');
        if ($units > 1_000_000) {
            throw new InvalidArgumentException('Le taux ne peut pas dépasser 100 %.');
        }

        return $units;
    }

    private static function divideRounded(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }
}
