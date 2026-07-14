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
        return sprintf('%d.%02d', intdiv($amount, 100), $amount % 100);
    }
}
