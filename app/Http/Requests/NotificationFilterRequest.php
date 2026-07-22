<?php

namespace App\Http\Requests;

use App\Models\InternalNotification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', InternalNotification::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(['all', 'unread'])],
            'priority' => ['nullable', Rule::in(['information', 'warning', 'urgent'])],
            'category' => ['nullable', Rule::in(['reservation', 'contract', 'fleet', 'insurance', 'maintenance', 'finance'])],
        ];
    }
}
