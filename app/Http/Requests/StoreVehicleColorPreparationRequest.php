<?php

namespace App\Http\Requests;

use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreVehicleColorPreparationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) config('intelligence.vehicle_color_v8.enabled')
            && ($this->user()?->can('create', Vehicle::class) ?? false);
    }

    public function rules(): array
    {
        return [
            'agency_id' => [
                'required',
                'integer',
                Rule::exists('agencies', 'id')->where('tenant_id', $this->user()?->tenant_id),
            ],
            'image' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:'.(int) config('intelligence.vehicle_color_v8.max_upload_kilobytes'),
                'dimensions:max_width='.(int) config('intelligence.vehicle_color_v8.max_image_dimension')
                    .',max_height='.(int) config('intelligence.vehicle_color_v8.max_image_dimension'),
            ],
            'tenant_id' => ['prohibited'],
            'vehicle_id' => ['prohibited'],
            'run_id' => ['prohibited'],
            'model_path' => ['prohibited'],
            'python_binary' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $declared = [
                'agency_id',
                'image',
                'tenant_id',
                'vehicle_id',
                'run_id',
                'model_path',
                'python_binary',
            ];
            foreach (array_diff(array_keys($this->except(['_token', '_method'])), $declared) as $key) {
                $validator->errors()->add(
                    $key,
                    'Ce champ n’est pas autorisé pour l’analyse de couleur.',
                );
            }
        }];
    }
}
