<?php

namespace App\Http\Requests;

use App\Models\Vehicle;
use App\Support\Intelligence\VehiclePlate\VehiclePlateDetectorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreVehicleRegistrationPreparationRequest extends FormRequest
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
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.(int) config(
                    'intelligence.vehicle_plate_hybrid_review.max_upload_kilobytes',
                ),
                'dimensions:max_width='.(int) config(
                    'intelligence.vehicle_plate_hybrid_review.max_image_dimension',
                ).',max_height='.(int) config(
                    'intelligence.vehicle_plate_hybrid_review.max_image_dimension',
                ),
            ],
            'tenant_id' => ['prohibited'],
            'vehicle_id' => ['prohibited'],
            'run_id' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $image = $this->file('image');
            if ($image instanceof UploadedFile && ! $validator->errors()->has('image')) {
                $dimensions = @getimagesize($image->getRealPath());
                $maxDimension = (int) config(
                    'intelligence.vehicle_plate_hybrid_review.max_image_dimension',
                );
                $width = is_array($dimensions) ? ($dimensions[0] ?? null) : null;
                $height = is_array($dimensions) ? ($dimensions[1] ?? null) : null;
                $mime = is_array($dimensions) ? ($dimensions['mime'] ?? null) : null;
                if (! is_int($width)
                    || ! is_int($height)
                    || $width < 1
                    || $height < 1
                    || $width > $maxDimension
                    || $height > $maxDimension
                    || $width * $height > $maxDimension * $maxDimension
                    || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                    $validator->errors()->add(
                        'image',
                        'La photo doit être une image JPEG, PNG ou WebP valide et de dimensions autorisées.',
                    );
                }
            }

            $declared = [
                'agency_id',
                'input_kind',
                'image',
                'tenant_id',
                'vehicle_id',
                'run_id',
            ];
            foreach (array_diff(array_keys($this->except(['_token', '_method'])), $declared) as $key) {
                $validator->errors()->add(
                    $key,
                    'Ce champ n’est pas autorisé pour la lecture de l’immatriculation.',
                );
            }
        }];
    }
}
