<?php

namespace App\Http\Requests;

use App\Enums\IntelligenceResultBatchDecision;
use App\Models\FleetReallocationProposal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RecordFleetReallocationDecisionRequest extends FormRequest
{
    public const ACCEPT_REASON_CODES = [
        'CONSULTATIVE_PLAN_ACCEPTED_FOR_DEMO',
        'HUMAN_REVIEW_COMPLETED_DEMO_ONLY',
    ];

    public const REJECT_REASON_CODES = [
        'PLAN_NOT_OPERATIONALLY_SUITABLE',
        'INTEGRITY_OR_LINEAGE_REJECTED',
        'DEMO_REJECTED',
    ];

    public const REASON_CODES = [
        ...self::ACCEPT_REASON_CODES,
        ...self::REJECT_REASON_CODES,
    ];

    public function authorize(): bool
    {
        $proposal = $this->route('reallocationProposal');

        return $proposal instanceof FleetReallocationProposal
            && ($this->user()?->can('review', $proposal) ?? false);
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(IntelligenceResultBatchDecision::class)],
            'reason_code' => ['required', Rule::in(self::REASON_CODES)],
            'tenant_id' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'effect' => ['prohibited'],
            'note' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = ['decision', 'reason_code', 'tenant_id', 'agency_id', 'effect', 'note'];
            foreach (array_diff(array_keys($this->except(['_token', '_method'])), $allowed) as $key) {
                $validator->errors()->add($key, 'Ce champ n’est pas autorisé par la revue de réallocation.');
            }

            $decision = $this->input('decision');
            $reasonCode = $this->input('reason_code');
            if (! is_string($reasonCode) || ! in_array($reasonCode, self::REASON_CODES, true)) {
                return;
            }

            $acceptReason = in_array($reasonCode, self::ACCEPT_REASON_CODES, true);
            if (($decision === IntelligenceResultBatchDecision::AcceptedForDemoReview->value) !== $acceptReason) {
                $validator->errors()->add(
                    'reason_code',
                    'Le motif doit correspondre à la décision humaine choisie.',
                );
            }
        }];
    }
}
