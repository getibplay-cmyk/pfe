<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class ReversePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $payment = $this->route('payment');

        return $this->user()?->hasPermission('payment.reverse')
            && $payment
            && ($this->user()->agency_id === null || $this->user()->agency_id === $payment->agency_id);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:120'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
