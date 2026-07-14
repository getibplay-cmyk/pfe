<?php

namespace App\Actions\Rentals;

use App\Models\RentalContract;
use App\Models\VehicleInspection;

class CompareVehicleInspections
{
    public function handle(VehicleInspection $departure, VehicleInspection $return): array
    {
        $departureItems = $departure->items->keyBy('item_code');
        $changes = $return->items->map(function ($item) use ($departureItems) {
            $before = $departureItems->get($item->item_code);

            return ['item_code' => $item->item_code, 'label' => $item->label, 'before' => $before?->condition?->value, 'after' => $item->condition->value, 'changed' => $before?->condition !== $item->condition];
        })->values()->all();
        $fuelDelta = $this->fromSignedHundredths($this->hundredths($return->fuel_level) - $this->hundredths($departure->fuel_level));

        return ['mileage_delta' => $return->mileage - $departure->mileage, 'fuel_delta' => $fuelDelta, 'items' => $changes, 'damage_candidates' => array_values(array_filter($changes, fn ($item) => $item['changed'] && in_array($item['after'], ['damaged', 'missing'], true)))];
    }

    public function futureConflicts(RentalContract $contract, VehicleInspection $return): int
    {
        return $contract->vehicle->blocks()->where('status', 'active')->where('id', '!=', $contract->vehicleBlock?->id)->where('starts_at', '<', $return->inspected_at)->where('ends_at', '>', $contract->expected_return_at)->count();
    }

    private function hundredths(string|int $value): int
    {
        preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim((string) $value), $matches);

        return ((int) ($matches[1] ?? 0) * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function fromSignedHundredths(int $value): string
    {
        $sign = $value < 0 ? '-' : '';
        $absolute = abs($value);

        return $sign.sprintf('%d.%02d', intdiv($absolute, 100), $absolute % 100);
    }
}
