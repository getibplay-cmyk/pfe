<?php

namespace App\Http\Requests;

use App\Models\VehiclePlatePredictionRun;
use App\Support\Intelligence\VehiclePlate\VehiclePlateDetectorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreVehiclePlatePredictionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->exists('input_kind')) {
            $this->merge(['input_kind' => VehiclePlateDetectorContract::FULL_IMAGE]);
        }
    }

    public function authorize(): bool
    {
        return (bool) config('intelligence.vehicle_plate_hybrid_review.enabled')
            && ($this->user()?->can('create', VehiclePlatePredictionRun::class) ?? false);
    }

    public function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'integer'],
            'input_kind' => [
                'required',
                'string',
                Rule::in(VehiclePlateDetectorContract::INPUT_KINDS),
            ],
            'image' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:'.(int) config('intelligence.vehicle_plate_hybrid_review.max_upload_kilobytes'),
                'dimensions:max_width='.(int) config('intelligence.vehicle_plate_hybrid_review.max_image_dimension')
                    .',max_height='.(int) config('intelligence.vehicle_plate_hybrid_review.max_image_dimension'),
            ],
            'tenant_id' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'run_id' => ['prohibited'],
            'model_name' => ['prohibited'],
            'python_binary' => ['prohibited'],
            'detector_model_path' => ['prohibited'],
            'detector_model_sha256' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $declared = [
                'vehicle_id',
                'input_kind',
                'image',
                'tenant_id',
                'agency_id',
                'run_id',
                'model_name',
                'python_binary',
                'detector_model_path',
                'detector_model_sha256',
            ];
            foreach (array_diff(array_keys($this->except(['_token', '_method'])), $declared) as $key) {
                $validator->errors()->add($key, 'Ce champ n’est pas autorisé pour l’analyse de plaque.');
            }
        }];
    }
}
