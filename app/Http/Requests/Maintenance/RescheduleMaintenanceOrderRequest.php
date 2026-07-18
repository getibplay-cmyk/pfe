<?php

namespace App\Http\Requests\Maintenance;

use Illuminate\Foundation\Http\FormRequest;

class RescheduleMaintenanceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reschedule', $this->route('maintenance')) ?? false;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'vehicle_id' => ['prohibited'],
            'status' => ['prohibited'],
            'scheduled_start_at' => ['required', 'date'],
            'scheduled_end_at' => ['required', 'date', 'after:scheduled_start_at', 'after:now'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
