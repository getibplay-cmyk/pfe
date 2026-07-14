<?php

namespace App\Support\Finance;

use App\Models\DepositTransaction;
use App\Models\RentalContract;
use App\Support\Pricing\DecimalMoney;

class DepositLedger
{
    public function totals(RentalContract $contract): array
    {
        $rows = DepositTransaction::where('rental_contract_id', $contract->id)->orderBy('id')->get();
        $byId = $rows->keyBy('id');
        $totals = ['received' => 0, 'retained' => 0, 'refunded' => 0, 'adjustment_in' => 0, 'adjustment_out' => 0];
        foreach ($rows as $row) {
            $amount = DecimalMoney::toMinorUnits($row->amount);
            if ($row->transaction_type === 'reversal') {
                $original = $byId->get($row->reversal_of_id);
                if ($original && isset($totals[$original->transaction_type])) {
                    $totals[$original->transaction_type] -= $amount;
                }
            } elseif (isset($totals[$row->transaction_type])) {
                $totals[$row->transaction_type] += $amount;
            }
        }
        $balance = $totals['received'] + $totals['adjustment_in'] - $totals['retained'] - $totals['refunded'] - $totals['adjustment_out'];

        return [...$totals, 'balance' => $balance];
    }

    public function syncContract(RentalContract $contract): array
    {
        $totals = $this->totals($contract);
        $contract->forceFill([
            'deposit_received' => DecimalMoney::fromMinorUnits($totals['received'] + $totals['adjustment_in']),
            'deposit_retained' => DecimalMoney::fromMinorUnits($totals['retained'] + $totals['adjustment_out']),
            'deposit_refunded' => DecimalMoney::fromMinorUnits($totals['refunded']),
        ])->save();

        return $totals;
    }
}
