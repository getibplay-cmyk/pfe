<?php

namespace App\Http\Requests;

use App\Models\VehicleDamagePredictionRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class StoreVehicleDamagePredictionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) config('intelligence.vehicle_damage_v1.enabled')
            && ($this->user()?->can('create', VehicleDamagePredictionRun::class) ?? false);
    }

    public function rules(): array
    {
        return [
            'vehicle_inspection_id' => ['required', 'integer'],
            'image' => [
                'required',
                File::image()
                    ->types(['jpg', 'jpeg', 'png', 'webp'])
                    ->max((int) config('intelligence.vehicle_damage_v1.max_upload_kilobytes'))
                    ->dimensions(
                        Rule::dimensions()
                            ->maxWidth((int) config('intelligence.vehicle_damage_v1.max_image_dimension'))
                            ->maxHeight((int) config('intelligence.vehicle_damage_v1.max_image_dimension')),
                    ),
            ],
            'tenant_id' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'vehicle_id' => ['prohibited'],
            'run_id' => ['prohibited'],
            'model_path' => ['prohibited'],
            'model_sha256' => ['prohibited'],
            'python_binary' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $declared = [
                'vehicle_inspection_id',
                'image',
                'tenant_id',
                'agency_id',
                'vehicle_id',
                'run_id',
                'model_path',
                'model_sha256',
                'python_binary',
            ];
            foreach (array_diff(array_keys($this->except(['_token', '_method'])), $declared) as $key) {
                $validator->errors()->add($key, 'Ce champ n’est pas autorisé pour l’analyse des dommages.');
            }
        }];
    }
}
