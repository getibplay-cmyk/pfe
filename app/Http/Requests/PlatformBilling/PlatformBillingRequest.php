<?php

namespace App\Http\Requests\PlatformBilling;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

abstract class PlatformBillingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->is_active && $this->user()->is_platform_admin);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $declared = collect(array_keys($this->rules()))
                ->map(static fn (string $key): string => Str::before($key, '.'))
                ->unique()
                ->all();
            $unknown = array_diff(
                array_keys($this->except(['_token', '_method'])),
                $declared,
            );

            foreach ($unknown as $key) {
                $validator->errors()->add($key, 'Ce champ n’est pas autorisé.');
            }
        }];
    }
}
