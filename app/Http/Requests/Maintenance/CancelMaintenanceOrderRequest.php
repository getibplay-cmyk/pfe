<?php

namespace App\Http\Requests\Maintenance;

use Illuminate\Foundation\Http\FormRequest;

class CancelMaintenanceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('maintenance');

        return $this->user()?->hasPermission('maintenance.cancel')
            && $order
            && ($this->user()->agency_id === null || $this->user()->agency_id === $order->agency_id);
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000']];
    }
}
