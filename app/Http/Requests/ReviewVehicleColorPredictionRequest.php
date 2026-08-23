<?php

namespace App\Http\Requests;

use App\Enums\VehicleColorReviewDecision;
use App\Models\VehicleColorPredictionRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReviewVehicleColorPredictionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $run = $this->route('colorPrediction');

        return $run instanceof VehicleColorPredictionRun
            && ($this->user()?->can('review', $run) ?? false);
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(VehicleColorReviewDecision::class)],
            'note' => ['nullable', 'string', 'max:500'],
            'tenant_id' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'vehicle_id' => ['prohibited'],
            'suggested_color' => ['prohibited'],
            'confidence' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $declared = [
                'decision',
                'note',
                'tenant_id',
                'agency_id',
                'vehicle_id',
                'suggested_color',
                'confidence',
            ];
            foreach (array_diff(array_keys($this->except(['_token', '_method'])), $declared) as $key) {
                $validator->errors()->add($key, 'Ce champ n’est pas autorisé pour la revue humaine.');
            }
        }];
    }
}
