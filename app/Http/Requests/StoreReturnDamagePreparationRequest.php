<?php

namespace App\Http\Requests;

use App\Enums\RentalContractStatus;
use App\Models\RentalContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class StoreReturnDamagePreparationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $contract = $this->route('contract');
        $user = $this->user();

        return $contract instanceof RentalContract
            && $contract->status === RentalContractStatus::Active
            && (bool) config('intelligence.vehicle_damage_v1.enabled')
            && ($user?->can('return', $contract) ?? false)
            && ($user?->hasPermission('inspection.manage') ?? false)
            && ($user?->hasPermission('prediction.view') ?? false)
            && ($user?->hasPermission('prediction.damage.review') ?? false);
    }

    public function rules(): array
    {
        return [
            'image' => [
                'required',
                File::image()
                    ->types(['jpg', 'jpeg', 'png', 'webp'])
                    ->max((int) config('intelligence.vehicle_damage_v1.max_upload_kilobytes'))
                    ->dimensions(
                        Rule::dimensions()
                            ->maxWidth((int) config('intelligence.vehicle_damage_v1.max_image_dimension'))
                            ->maxHeight((int) config('intelligence.vehicle_damage_v1.max_image_dimension')),
                    ),
            ],
            'tenant_id' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'vehicle_id' => ['prohibited'],
            'vehicle_inspection_id' => ['prohibited'],
            'rental_contract_id' => ['prohibited'],
            'run_id' => ['prohibited'],
            'input_stored_path' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = [
                'image',
                'tenant_id',
                'agency_id',
                'vehicle_id',
                'vehicle_inspection_id',
                'rental_contract_id',
                'run_id',
                'input_stored_path',
            ];
            foreach (array_diff(array_keys($this->except('_token')), $allowed) as $key) {
                $validator->errors()->add(
                    $key,
                    'Ce champ n’est pas autorisé pour l’analyse de retour.',
                );
            }
        }];
    }
}
