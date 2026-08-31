<?php

namespace App\Http\Requests;

use App\Enums\RentalUsageAnomalyReviewDecision;
use App\Models\RentalContract;
use App\Models\RentalUsageAnomalyResult;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReviewRentalUsageAnomalyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $result = $this->route('anomalyResult');
        $contract = $this->route('contract');
        $resource = $result instanceof RentalUsageAnomalyResult ? $result : $contract;
        if (($resource instanceof RentalUsageAnomalyResult || $resource instanceof RentalContract)
            && $this->user()?->agency_id !== null
            && $this->user()->agency_id !== $resource->agency_id) {
            abort(404);
        }

        if ($result instanceof RentalUsageAnomalyResult) {
            return $this->user()?->can('review', $result) ?? false;
        }

        return $contract instanceof RentalContract
            && ($this->user()?->can('view', $contract) ?? false)
            && ($this->user()?->hasPermission('prediction.view') ?? false)
            && ($this->user()?->hasPermission('prediction.anomaly.review') ?? false);
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(RentalUsageAnomalyReviewDecision::class)],
            'note' => ['nullable', 'string', 'max:500'],
            'tenant_id' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'reviewed_by' => ['prohibited'],
            'effect' => ['prohibited'],
            'rental_contract_id' => ['prohibited'],
            'primary_score' => ['prohibited'],
            'challenger_score' => ['prohibited'],
            'action' => ['prohibited'],
            'fee' => ['prohibited'],
            'sanction' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $declared = [
                'decision', 'note', 'tenant_id', 'agency_id', 'reviewed_by', 'effect',
                'rental_contract_id', 'primary_score', 'challenger_score', 'action', 'fee', 'sanction',
            ];
            foreach (array_diff(array_keys($this->except(['_token', '_method'])), $declared) as $key) {
                $validator->errors()->add($key, 'Ce champ n’est pas autorisé pour la revue humaine.');
            }
        }];
    }
}
