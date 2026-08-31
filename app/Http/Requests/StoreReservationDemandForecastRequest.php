<?php

namespace App\Http\Requests;

use App\Models\Reservation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreReservationDemandForecastRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->is_active
            && $user->can('viewAny', Reservation::class)
            && $user->hasPermission('prediction.view')
            && $user->hasPermission('prediction.forecast.import')
            && ($user->agency_id === null || $user->agency_id === (int) $this->input('agency_id'));
    }

    public function rules(): array
    {
        return [
            'agency_id' => [
                'required',
                'integer',
                Rule::exists('agencies', 'id')->where('tenant_id', $this->user()?->tenant_id),
            ],
            'tenant_id' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'history' => ['prohibited'],
            'features' => ['prohibited'],
            'forecasts' => ['prohibited'],
            'model_path' => ['prohibited'],
            'python_binary' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $declared = [
                'agency_id',
                'tenant_id',
                'requester_id',
                'history',
                'features',
                'forecasts',
                'model_path',
                'python_binary',
            ];
            foreach (array_diff(array_keys($this->except(['_token', '_method'])), $declared) as $key) {
                $validator->errors()->add(
                    $key,
                    'Ce champ n’est pas autorisé pour la prévision de demande.',
                );
            }
        }];
    }
}
