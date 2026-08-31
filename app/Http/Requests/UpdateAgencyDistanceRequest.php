<?php

namespace App\Http\Requests;

use App\Enums\AgencyDistanceSourceType;
use App\Models\AgencyDistance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAgencyDistanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $distance = $this->route('agencyDistance');

        return $distance instanceof AgencyDistance
            && ($this->user()?->can('update', $distance) ?? false);
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'from_agency_id' => ['prohibited'],
            'to_agency_id' => ['prohibited'],
            'verified_by_user_id' => ['prohibited'],
            'verified_at' => ['prohibited'],
            'active' => ['prohibited'],
            'id' => ['prohibited'],
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
                'tenant_id', 'from_agency_id', 'to_agency_id', 'verified_by_user_id',
                'verified_at', 'active', 'id', 'distance_km', 'source_type',
                'source_reference', 'same_distance_both_ways',
            ];
            foreach (array_diff(array_keys($this->except(['_token', '_method'])), $allowed) as $key) {
                $validator->errors()->add($key, 'Ce champ n’est pas autorisé pour cette correction.');
            }
        }];
    }
}
