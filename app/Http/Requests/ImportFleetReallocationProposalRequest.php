<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class ImportFleetReallocationProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->agency_id === null
            && $user->hasPermission('prediction.demo.review');
    }

    public function rules(): array
    {
        return [
            'proposal' => [
                'required',
                'extensions:json',
                File::types(['json'])->max((int) config('intelligence.fleet_reallocation.max_upload_kilobytes')),
            ],
            'tenant_id' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'stored_path' => ['prohibited'],
            'operational_effect' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = ['proposal', 'tenant_id', 'agency_id', 'stored_path', 'operational_effect'];
            foreach (array_diff(array_keys($this->except(['_token', '_method'])), $allowed) as $key) {
                $validator->errors()->add($key, 'Ce champ n’est pas autorisé par le contrat de réallocation.');
            }
        }];
    }
}
