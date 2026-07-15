<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class AllocatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $payment = $this->route('payment');
        $invoice = $this->route('invoice');

        return $this->user()?->hasPermission('payment.allocate')
            && $payment
            && $invoice
            && ($this->user()->agency_id === null || ($payment->agency_id === $this->user()->agency_id && $invoice->agency_id === $this->user()->agency_id));
    }

    public function rules(): array
    {
        return ['amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/']];
    }
}
