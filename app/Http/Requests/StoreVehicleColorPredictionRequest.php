<?php

namespace App\Http\Requests;

use App\Models\VehicleColorPredictionRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreVehicleColorPredictionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) config('intelligence.vehicle_color_v8.enabled')
            && ($this->user()?->can('create', VehicleColorPredictionRun::class) ?? false);
    }

    public function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'integer'],
            'image' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:'.(int) config('intelligence.vehicle_color_v8.max_upload_kilobytes'),
                'dimensions:max_width=8000,max_height=8000',
            ],
            'tenant_id' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'run_id' => ['prohibited'],
            'model_path' => ['prohibited'],
            'python_binary' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $declared = [
                'vehicle_id',
                'image',
                'tenant_id',
                'agency_id',
                'run_id',
                'model_path',
                'python_binary',
            ];
            foreach (array_diff(array_keys($this->except(['_token', '_method'])), $declared) as $key) {
                $validator->errors()->add($key, 'Ce champ n’est pas autorisé pour l’analyse de couleur.');
            }
        }];
    }
}
