<?php

namespace App\Http\Requests\Maintenance;

use Illuminate\Foundation\Http\FormRequest;

class CompleteMaintenanceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('maintenance');

        return $order && ($this->user()?->can('complete', $order) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['return_to_active' => $this->boolean('return_to_active')]);
    }

    public function rules(): array
    {
        return [
            'actual_cost' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'mileage' => ['required', 'integer', 'min:0'],
            'next_due_date' => ['nullable', 'date'],
            'next_due_mileage' => ['nullable', 'integer', 'min:0'],
            'return_to_active' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
