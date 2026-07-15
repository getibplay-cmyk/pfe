<?php

namespace App\Http\Requests\Maintenance;

use Illuminate\Foundation\Http\FormRequest;

class StoreMaintenanceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $agencyId = $this->integer('agency_id');

        return $this->user()?->hasPermission('maintenance.create')
            && ($this->user()->agency_id === null || $this->user()->agency_id === $agencyId);
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'agency_id' => ['required', 'integer'],
            'vehicle_id' => ['required', 'integer'],
            'maintenance_type' => ['required', 'in:preventive,corrective,inspection,repair'],
            'priority' => ['required', 'in:low,normal,high,critical'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'scheduled_start_at' => ['nullable', 'date'],
            'scheduled_end_at' => ['nullable', 'date', 'after:scheduled_start_at'],
            'estimated_cost' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'supplier' => ['nullable', 'string', 'max:255'],
        ];
    }
}
