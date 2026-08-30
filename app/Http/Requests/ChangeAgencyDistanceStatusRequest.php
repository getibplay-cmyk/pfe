<?php

namespace App\Http\Requests;

use App\Models\AgencyDistance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ChangeAgencyDistanceStatusRequest extends FormRequest
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
            'active' => ['prohibited'],
            'verified_by_user_id' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = ['tenant_id', 'active', 'verified_by_user_id'];
            foreach (array_diff(array_keys($this->except(['_token', '_method'])), $allowed) as $key) {
                $validator->errors()->add($key, 'Ce champ n’est pas autorisé pour ce changement d’état.');
            }
        }];
    }
}
