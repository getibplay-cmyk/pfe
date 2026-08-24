<?php

namespace App\Http\Requests;

use App\Enums\VehicleDamageReviewDecision;
use App\Models\VehicleDamagePredictionRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReviewVehicleDamagePredictionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $run = $this->route('damagePrediction');

        return $run instanceof VehicleDamagePredictionRun
            && ($this->user()?->can('review', $run) ?? false);
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(VehicleDamageReviewDecision::class)],
            'note' => ['nullable', 'string', 'max:500'],
            'tenant_id' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'vehicle_id' => ['prohibited'],
            'suggested_damage' => ['prohibited'],
            'max_probability_damage' => ['prohibited'],
            'candidate_regions' => ['prohibited'],
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
                'suggested_damage',
                'max_probability_damage',
                'candidate_regions',
            ];
            foreach (array_diff(array_keys($this->except(['_token', '_method'])), $declared) as $key) {
                $validator->errors()->add($key, 'Ce champ n’est pas autorisé pour la revue humaine.');
            }
        }];
    }
}
