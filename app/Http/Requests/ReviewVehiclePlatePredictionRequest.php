<?php

namespace App\Http\Requests;

use App\Enums\VehiclePlateReviewDecision;
use App\Models\VehiclePlatePredictionRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReviewVehiclePlatePredictionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('verified_canonical'))) {
            $normalized = preg_replace('/\s+/u', '', trim($this->input('verified_canonical')));
            $this->merge(['verified_canonical' => $normalized]);
        }
    }

    public function authorize(): bool
    {
        $run = $this->route('platePrediction');

        return $run instanceof VehiclePlatePredictionRun
            && ($this->user()?->can('review', $run) ?? false);
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(VehiclePlateReviewDecision::class)],
            'verified_canonical' => [
                'nullable',
                'exclude_if:decision,ignored',
                'required_if:decision,confirmed,corrected',
                'string',
                'max:16',
                'regex:/\A[1-9][0-9]{0,4}\|[أبدهوطيكلمنصفرس]\|[1-9][0-9]?\z/u',
            ],
            'note' => ['nullable', 'string', 'max:500'],
            'tenant_id' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'vehicle_id' => ['prohibited'],
            'suggested_canonical' => ['prohibited'],
            'confidence' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'verified_canonical.regex' => 'Utilisez le format canonique 12345|أ|7.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $declared = [
                'decision',
                'verified_canonical',
                'note',
                'tenant_id',
                'agency_id',
                'vehicle_id',
                'suggested_canonical',
                'confidence',
            ];
            foreach (array_diff(array_keys($this->except(['_token', '_method'])), $declared) as $key) {
                $validator->errors()->add($key, 'Ce champ n’est pas autorisé pour la correction humaine.');
            }
        }];
    }
}
