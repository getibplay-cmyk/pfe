<?php

namespace Tests\Unit;

use App\Support\Reporting\VehicleOwnershipCosts;
use PHPUnit\Framework\TestCase;

class VehicleOwnershipCostsTest extends TestCase
{
    public function test_full_lifetime_preserves_residual_value_and_stops_depreciating(): void
    {
        $profile = $this->profile();
        $profile['residual_value'] = '660.00';
        $result = (new VehicleOwnershipCosts)->project($profile, '2024-01-01', '2026-01-01');
        $this->assertSame(300000, $result['depreciation']);
        $this->assertSame(66000, $result['remaining_value']);
        $this->assertSame(0, (new VehicleOwnershipCosts)->project($profile, '2025-01-01', '2026-01-01')['depreciation']);
    }

    public function test_adjacent_reports_add_exactly_without_rounding_drift_across_leap_day(): void
    {
        $calculator = new VehicleOwnershipCosts;
        $whole = $calculator->project($this->profile(), '2024-01-01', '2025-01-01');
        $first = $calculator->project($this->profile(), '2024-01-01', '2024-02-29');
        $second = $calculator->project($this->profile(), '2024-02-29', '2025-01-01');
        foreach (['depreciation', 'insurance_budget', 'other_unrecorded', 'days'] as $key) {
            $this->assertSame($whole[$key], $first[$key] + $second[$key]);
        }
        $this->assertSame(366000, $whole['depreciation']);
        $this->assertSame(36600, $whole['insurance_budget']);
        $this->assertSame(37200, $whole['other_unrecorded']);
    }

    public function test_effective_date_and_acquisition_bound_the_projection_using_calendar_days(): void
    {
        $profile = $this->profile();
        $profile['effective_from'] = '2024-03-30';
        $result = (new VehicleOwnershipCosts)->project($profile, '2024-03-01', '2024-04-01');
        $this->assertSame(2, $result['days']);
        $this->assertSame(2000, $result['depreciation']);
        $this->assertSame(200, $result['insurance_budget']);
        $this->assertSame(200, $result['other_unrecorded']);
    }

    private function profile(): array
    {
        return ['acquired_on' => '2024-01-01', 'effective_from' => '2024-01-01', 'acquisition_cost' => '3660.00', 'residual_value' => '0.00', 'depreciation_months' => 12, 'annual_insurance' => '366.00', 'monthly_unrecorded_costs' => '31.00'];
    }
}
