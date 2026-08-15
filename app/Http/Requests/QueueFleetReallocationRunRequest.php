<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class QueueFleetReallocationRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) config('intelligence.fleet_reallocation.runtime_enabled')
            && $user !== null
            && $user->agency_id === null
            && $user->hasPermission('prediction.demo.review');
    }

    public function rules(): array
    {
        return [
            'forecast_horizon' => ['required', 'integer', 'between:1,7'],
            'tenant_id' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'scenario_number' => ['prohibited'],
            'python_binary' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = [
                'forecast_horizon',
                'tenant_id',
                'agency_id',
                'scenario_number',
                'python_binary',
            ];
            foreach (array_diff(array_keys($this->except(['_token', '_method'])), $allowed) as $key) {
                $validator->errors()->add($key, 'Ce champ n’est pas autorisé pour l’exécution OR-Tools.');
            }
        }];
    }
}
