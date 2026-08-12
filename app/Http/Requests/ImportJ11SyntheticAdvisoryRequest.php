<?php

namespace App\Http\Requests;

use App\Enums\J11AdvisoryModule;
use App\Models\AiAdvisoryRecordDemo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ImportJ11SyntheticAdvisoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AiAdvisoryRecordDemo::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'module_id' => ['required', Rule::enum(J11AdvisoryModule::class)],
            'tenant_id' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'payload' => ['prohibited'],
            'feature_flag' => ['prohibited'],
            'ready_for_saas' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = ['module_id', 'tenant_id', 'agency_id', 'payload', 'feature_flag', 'ready_for_saas'];
            foreach (array_diff(array_keys($this->except(['_token', '_method'])), $allowed) as $key) {
                $validator->errors()->add($key, 'Ce champ n’est pas autorisé par le contrat J12.');
            }
        }];
    }
}
