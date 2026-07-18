<?php

namespace App\Http\Requests\Maintenance;

use Illuminate\Foundation\Http\FormRequest;

class CancelMaintenanceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('maintenance');

        return $order && ($this->user()?->can('cancel', $order) ?? false);
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000']];
    }
}
