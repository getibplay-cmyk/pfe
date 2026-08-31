<?php

namespace App\Http\Requests;

use App\Enums\AgencyDistanceSourceType;
use App\Models\AgencyDistance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAgencyDistanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AgencyDistance::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;
        $activeAgency = Rule::exists('agencies', 'id')->where(
            fn ($query) => $query
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->whereNull('deleted_at'),
        );

        return [
            'tenant_id' => ['prohibited'],
            'verified_by_user_id' => ['prohibited'],
            'verified_at' => ['prohibited'],
            'active' => ['prohibited'],
            'id' => ['prohibited'],
            'from_agency_id' => ['required', 'integer', $activeAgency],
            'to_agency_id' => ['required', 'integer', 'different:from_agency_id', $activeAgency],
            'distance_km' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'lte:10000'],
            'source_type' => ['required', Rule::in([AgencyDistanceSourceType::ManualVerified->value])],
            'source_reference' => ['nullable', 'string', 'max:1000'],
            'same_distance_both_ways' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = [
                'tenant_id', 'verified_by_user_id', 'verified_at', 'active', 'id',
                'from_agency_id', 'to_agency_id', 'distance_km', 'source_type',
                'source_reference', 'same_distance_both_ways',
            ];
            foreach (array_diff(array_keys($this->except(['_token', '_method'])), $allowed) as $key) {
                $validator->errors()->add($key, 'Ce champ n’est pas autorisé pour une distance inter-agences.');
            }
        }];
    }
}
