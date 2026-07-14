<?php

namespace App\Actions\Pricing;

use App\Models\PricingRule;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class ResolvePricingRule
{
    public function handle(int $agencyId, int $vehicleCategoryId, CarbonInterface $startsAt): PricingRule
    {
        $date = $startsAt->toDateString();
        $rule = PricingRule::query()
            ->where('vehicle_category_id', $vehicleCategoryId)
            ->where('is_active', true)
            ->whereDate('valid_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->where(fn ($query) => $query->whereNull('agency_id')->orWhere('agency_id', $agencyId))
            ->orderByRaw('CASE WHEN agency_id = ? THEN 0 ELSE 1 END', [$agencyId])
            ->orderByDesc('priority')
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->first();

        if (! $rule) {
            throw ValidationException::withMessages(['pricing_rule' => 'Aucune règle tarifaire active ne couvre cette agence, cette catégorie et cette date.']);
        }

        return $rule;
    }
}
