<?php

namespace App\Support\Reporting;

use App\Support\Pricing\DecimalMoney;
use Carbon\CarbonImmutable;

/** Calendar-day projections, rounded cumulatively in integer cents. Never ledger entries. */
final class VehicleOwnershipCosts
{
    public function project(array $profile, string $from, string $until): array
    {
        $acquired = CarbonImmutable::parse($profile['acquired_on'], 'UTC')->startOfDay();
        $start = CarbonImmutable::parse($from, 'UTC')->startOfDay()->max($acquired)->max(CarbonImmutable::parse($profile['effective_from'], 'UTC')->startOfDay());
        $end = CarbonImmutable::parse($until, 'UTC')->startOfDay();
        $cost = DecimalMoney::toMinorUnits($profile['acquisition_cost']);
        $residual = DecimalMoney::toMinorUnits($profile['residual_value']);
        $finish = $acquired->addMonthsNoOverflow((int) $profile['depreciation_months']);
        $duration = (int) $acquired->diffInDays($finish);
        $elapsed = fn (CarbonImmutable $date) => max(0, min($duration, (int) $acquired->diffInDays($date, false)));
        $depreciated = fn (CarbonImmutable $date) => $this->fraction($cost - $residual, $elapsed($date), $duration);
        $result = ['depreciation' => 0, 'insurance_budget' => 0, 'other_unrecorded' => 0, 'days' => 0, 'remaining_value' => $cost - $depreciated($end), 'acquisition_cost' => $cost, 'residual_value' => $residual];
        if ($end->lte($start)) {
            return $result;
        }
        $result['days'] = (int) $start->diffInDays($end);
        $result['depreciation'] = $depreciated($end) - $depreciated($start);
        $result['insurance_budget'] = $this->periodic(DecimalMoney::toMinorUnits($profile['annual_insurance']), $start, $end, true);
        $result['other_unrecorded'] = $this->periodic(DecimalMoney::toMinorUnits($profile['monthly_unrecorded_costs']), $start, $end, false);

        return $result;
    }

    private function periodic(int $amount, CarbonImmutable $start, CarbonImmutable $end, bool $annual): int
    {
        $total = 0;
        while ($start->lt($end)) {
            $periodStart = $annual ? $start->startOfYear() : $start->startOfMonth();
            $periodEnd = $annual ? $periodStart->addYear() : $periodStart->addMonth();
            $sliceEnd = $end->min($periodEnd);
            $days = (int) $periodStart->diffInDays($periodEnd);
            $total += $this->fraction($amount, (int) $periodStart->diffInDays($sliceEnd), $days) - $this->fraction($amount, (int) $periodStart->diffInDays($start), $days);
            $start = $sliceEnd;
        }

        return $total;
    }

    private function fraction(int $amount, int $numerator, int $denominator): int
    {
        return intdiv($amount * $numerator + intdiv($denominator, 2), $denominator);
    }
}
