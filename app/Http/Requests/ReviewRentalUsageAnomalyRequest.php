<?php

namespace App\Http\Requests;

use App\Enums\RentalUsageAnomalyReviewDecision;
use App\Models\RentalUsageAnomalyResult;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReviewRentalUsageAnomalyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $result = $this->route('anomalyResult');

        return $result instanceof RentalUsageAnomalyResult
            && ($this->user()?->can('review', $result) ?? false);
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(RentalUsageAnomalyReviewDecision::class)],
            'note' => ['nullable', 'string', 'max:500'],
            'tenant_id' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'rental_contract_id' => ['prohibited'],
            'primary_score' => ['prohibited'],
            'challenger_score' => ['prohibited'],
            'action' => ['prohibited'],
            'fee' => ['prohibited'],
            'sanction' => ['prohibited'],
            'budget' => ['required', Rule::in(['50', '100', '200', 50, 100, 200])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $declared = [
                'decision', 'note', 'tenant_id', 'agency_id', 'rental_contract_id',
                'primary_score', 'challenger_score', 'action', 'fee', 'sanction', 'budget',
            ];
            foreach (array_diff(array_keys($this->except(['_token', '_method'])), $declared) as $key) {
                $validator->errors()->add($key, 'Ce champ n’est pas autorisé pour la revue humaine.');
            }
        }];
    }
}
