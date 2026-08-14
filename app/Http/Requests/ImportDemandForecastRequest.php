<?php

namespace App\Http\Requests;

use App\Models\DemandHistoryExportRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class ImportDemandForecastRequest extends FormRequest
{
    public function authorize(): bool
    {
        $run = $this->route('historyRun');

        return $run instanceof DemandHistoryExportRun
            && ($this->user()?->can('importForecast', $run) ?? false);
    }

    public function rules(): array
    {
        return [
            'forecast_batch' => [
                'required',
                'extensions:json',
                File::types(['json'])->max((int) config('intelligence.demand_forecasting.max_upload_kilobytes')),
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
                'forecast_batch',
                'tenant_id',
                'agency_id',
                'run_id',
                'stored_path',
                'operational_effect',
            ];
            foreach (array_diff(array_keys($this->except(['_token', '_method'])), $allowed) as $key) {
                $validator->errors()->add($key, 'Ce champ n’est pas autorisé par le contrat de prévision.');
            }
        }];
    }
}
