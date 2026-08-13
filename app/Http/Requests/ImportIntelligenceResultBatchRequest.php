<?php

namespace App\Http\Requests;

use App\Models\IntelligenceDatasetExportRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class ImportIntelligenceResultBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $run = $this->route('exportRun');

        return $run instanceof IntelligenceDatasetExportRun
            && ($this->user()?->can('importResultBatch', $run) ?? false);
    }

    public function rules(): array
    {
        return [
            'result_batch' => [
                'required',
                'extensions:json',
                File::types(['json'])->max((int) config('intelligence.result_batches.max_upload_kilobytes')),
            ],
            'tenant_id' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'run_id' => ['prohibited'],
            'stored_path' => ['prohibited'],
            'operational_effect' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = [
                'result_batch',
                'tenant_id',
                'agency_id',
                'run_id',
                'stored_path',
                'operational_effect',
            ];
            foreach (array_diff(array_keys($this->except(['_token', '_method'])), $allowed) as $key) {
                $validator->errors()->add($key, 'Ce champ n’est pas autorisé par le contrat J14-B.');
            }
        }];
    }
}
