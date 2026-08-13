<?php

namespace App\Http\Requests;

use App\Enums\IntelligenceResultBatchDecision;
use App\Models\IntelligenceResultBatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RecordIntelligenceResultBatchDecisionRequest extends FormRequest
{
    public const REASON_CODES = [
        'SYNTHETIC_CONTRACT_REVIEW_ACCEPTED',
        'HUMAN_REVIEW_DEMO_ONLY',
        'SCHEMA_OR_LINEAGE_REJECTED',
        'INTEGRITY_REVIEW_REJECTED',
        'SCIENTIFIC_GATE_NOT_PASSED',
        'DEMO_REJECTED',
    ];

    public const ACCEPT_REASON_CODES = [
        'SYNTHETIC_CONTRACT_REVIEW_ACCEPTED',
        'HUMAN_REVIEW_DEMO_ONLY',
    ];

    public const REJECT_REASON_CODES = [
        'SCHEMA_OR_LINEAGE_REJECTED',
        'INTEGRITY_REVIEW_REJECTED',
        'SCIENTIFIC_GATE_NOT_PASSED',
        'DEMO_REJECTED',
    ];

    public function authorize(): bool
    {
        $batch = $this->route('resultBatch');

        return $batch instanceof IntelligenceResultBatch
            && ($this->user()?->can('review', $batch) ?? false);
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
                $validator->errors()->add($key, 'Ce champ n’est pas autorisé par le contrat J14-B.');
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
