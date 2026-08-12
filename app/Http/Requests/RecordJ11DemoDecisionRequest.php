<?php

namespace App\Http\Requests;

use App\Enums\J11DemoDecision;
use App\Models\AiAdvisoryRecordDemo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RecordJ11DemoDecisionRequest extends FormRequest
{
    public const REASON_CODES = [
        'SCHEMA_DEMO_ACCEPTED',
        'SCIENTIFIC_GATE_NOT_PASSED',
        'HUMAN_REVIEW_DEMO_ONLY',
        'DEMO_REJECTED',
    ];

    public function authorize(): bool
    {
        $record = $this->route('record');

        return $record instanceof AiAdvisoryRecordDemo
            && ($this->user()?->can('review', $record) ?? false);
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(J11DemoDecision::class)],
            'reason_code' => ['required', Rule::in(self::REASON_CODES)],
            'tenant_id' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'effect' => ['prohibited'],
            'payload' => ['prohibited'],
            'note' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = ['decision', 'reason_code', 'tenant_id', 'agency_id', 'effect', 'payload', 'note'];
            foreach (array_diff(array_keys($this->except(['_token', '_method'])), $allowed) as $key) {
                $validator->errors()->add($key, 'Ce champ n’est pas autorisé par le contrat J12.');
            }
        }];
    }
}
