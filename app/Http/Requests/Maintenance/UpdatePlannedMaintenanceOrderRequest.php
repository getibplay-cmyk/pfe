<?php

namespace App\Http\Requests\Maintenance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlannedMaintenanceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('maintenance')) ?? false;
    }

    public function rules(): array
    {
        $order = $this->route('maintenance');

        return [
            'tenant_id' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'status' => ['prohibited'],
            'actual_cost' => ['prohibited'],
            'created_by' => ['prohibited'],
            'vehicle_block' => ['prohibited'],
            'expense' => ['prohibited'],
            'vehicle_id' => ['required', 'integer', Rule::exists('vehicles', 'id')->where(fn ($query) => $query->where('tenant_id', $order->tenant_id)->where('agency_id', $order->agency_id))],
            'maintenance_type' => ['required', 'in:preventive,corrective,inspection,repair'],
            'priority' => ['required', 'in:low,normal,high,critical'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['present', 'nullable', 'string', 'max:5000'],
            'scheduled_start_at' => ['required', 'date'],
            'scheduled_end_at' => ['required', 'date', 'after:scheduled_start_at', 'after:now'],
            'mileage_at_opening' => ['present', 'nullable', 'integer', 'min:0'],
            'estimated_cost' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'supplier' => ['present', 'nullable', 'string', 'max:255'],
        ];
    }
}
