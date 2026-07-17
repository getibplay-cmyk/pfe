<?php

namespace App\Http\Requests\Finance;

use App\Models\Expense;
use Illuminate\Foundation\Http\FormRequest;

class RejectExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expense = $this->route('expense');

        return $expense instanceof Expense
            && $this->user()?->hasPermission('expense.reject')
            && ($this->user()->agency_id === null || $this->user()->agency_id === $expense->agency_id);
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'status' => ['prohibited'],
            'amount' => ['prohibited'],
            'tax_amount' => ['prohibited'],
            'currency' => ['prohibited'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
