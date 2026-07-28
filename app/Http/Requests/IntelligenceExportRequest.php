<?php

namespace App\Http\Requests;

class IntelligenceExportRequest extends IntelligenceFilterRequest
{
    public function authorize(): bool
    {
        return parent::authorize()
            && ($this->user()?->hasPermission('prediction.export') ?? false);
    }
}
