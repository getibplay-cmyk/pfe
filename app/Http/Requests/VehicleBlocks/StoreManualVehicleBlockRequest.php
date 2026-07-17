<?php

namespace App\Http\Requests\VehicleBlocks;

use App\Enums\VehicleOperationalStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreManualVehicleBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        $agencyId = $this->integer('agency_id');

        return $this->user()?->hasPermission('vehicle_block.manage')
            && ($this->user()->agency_id === null || $this->user()->agency_id === $agencyId);
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;
        $agencyId = $this->integer('agency_id');

        return [
            'tenant_id' => ['prohibited'],
            'block_type' => ['prohibited'],
            'status' => ['prohibited'],
            'created_by' => ['prohibited'],
            'released_at' => ['prohibited'],
            'reservation_id' => ['prohibited'],
            'rental_contract_id' => ['prohibited'],
            'maintenance_order_id' => ['prohibited'],
            'agency_id' => ['required', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $tenantId)],
            'vehicle_id' => [
                'required',
                'integer',
                Rule::exists('vehicles', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->where('agency_id', $agencyId)
                    ->where('operational_status', VehicleOperationalStatus::Active->value)
                    ->whereNull('deleted_at')),
            ],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at', 'after:now'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
