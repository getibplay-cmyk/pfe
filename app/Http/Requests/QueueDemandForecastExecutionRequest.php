<?php

namespace App\Http\Requests;

use App\Models\DemandHistoryExportRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class QueueDemandForecastExecutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $history = $this->route('historyRun');

        return (bool) config('intelligence.demand_forecasting.runtime_enabled')
            && $history instanceof DemandHistoryExportRun
            && ($this->user()?->can('importForecast', $history) ?? false);
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'run_id' => ['prohibited'],
            'snapshot_path' => ['prohibited'],
            'model_path' => ['prohibited'],
            'python_binary' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $declared = [
                'tenant_id',
                'agency_id',
                'run_id',
                'snapshot_path',
                'model_path',
                'python_binary',
            ];
            foreach (array_diff(array_keys($this->except(['_token', '_method'])), $declared) as $key) {
                $validator->errors()->add($key, 'Ce champ n’est pas autorisé pour générer une prévision de demande.');
            }
        }];
    }
}
