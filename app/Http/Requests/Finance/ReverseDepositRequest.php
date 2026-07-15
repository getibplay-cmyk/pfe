<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class ReverseDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        $deposit = $this->route('deposit');

        return $this->user()?->hasPermission('deposit.reverse')
            && $deposit
            && ($this->user()->agency_id === null || $this->user()->agency_id === $deposit->agency_id);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:120'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
