<?php

namespace App\Actions\Pricing;

use App\Models\PricingRule;
use App\Support\Pricing\DecimalMoney;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class CalculateReservationQuote
{
    public function handle(PricingRule $rule, CarbonInterface $startsAt, CarbonInterface $endsAt, string|int $optionsTotal = '0.00'): array
    {
        if ($endsAt->lte($startsAt)) {
            throw ValidationException::withMessages(['ends_at' => 'La fin doit être strictement postérieure au début.']);
        }

        $seconds = $startsAt->diffInSeconds($endsAt);
        $billedDays = max(1, (int) ceil($seconds / 86400));
        $billedDays = max($billedDays, $rule->minimum_days);
        if ($rule->maximum_days !== null && $billedDays > $rule->maximum_days) {
            throw ValidationException::withMessages(['ends_at' => "La durée dépasse le maximum de {$rule->maximum_days} jours autorisé par le tarif."]);
        }

        $dailyRate = DecimalMoney::toMinorUnits($rule->daily_rate);
        $options = DecimalMoney::toMinorUnits($optionsTotal);
        $subtotal = $dailyRate * $billedDays;
        $total = $subtotal + $options;

        return [
            'pricing_rule_id' => $rule->id,
            'billed_days' => $billedDays,
            'daily_rate' => DecimalMoney::fromMinorUnits($dailyRate),
            'subtotal' => DecimalMoney::fromMinorUnits($subtotal),
            'options_total' => DecimalMoney::fromMinorUnits($options),
            'total_amount' => DecimalMoney::fromMinorUnits($total),
            'deposit_amount' => DecimalMoney::fromMinorUnits(DecimalMoney::toMinorUnits($rule->deposit_amount)),
            'currency' => $rule->currency,
            'pricing_snapshot' => [
                'version' => 1,
                'pricing_rule' => ['id' => $rule->id, 'name' => $rule->name, 'valid_from' => $rule->valid_from->toDateString()],
                'period' => ['starts_at' => $startsAt->toIso8601String(), 'ends_at' => $endsAt->toIso8601String(), 'interval' => '[)'],
                'calculation' => [
                    'billed_days' => $billedDays,
                    'daily_rate' => DecimalMoney::fromMinorUnits($dailyRate),
                    'subtotal' => DecimalMoney::fromMinorUnits($subtotal),
                    'options_total' => DecimalMoney::fromMinorUnits($options),
                    'total_amount' => DecimalMoney::fromMinorUnits($total),
                    'deposit_amount' => DecimalMoney::fromMinorUnits(DecimalMoney::toMinorUnits($rule->deposit_amount)),
                    'currency' => $rule->currency,
                ],
                'conditions' => $rule->conditions ?? [],
            ],
        ];
    }
}
