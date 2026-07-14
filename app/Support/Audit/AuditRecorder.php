<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AuditRecorder
{
    private const SENSITIVE_KEYS = ['password', 'password_confirmation', 'remember_token', 'token', 'secret', 'api_key'];

    public function record(string $action, Model $subject, array $oldValues = [], array $newValues = []): AuditLog
    {
        $context = app(TenantContext::class);
        $request = request();

        return AuditLog::create([
            'agency_id' => $subject->getAttribute('agency_id') ?? $context->agencyId(),
            'user_id' => $request->user()?->getKey(),
            'action' => $action,
            'auditable_type' => $subject::class,
            'auditable_id' => $subject->getKey(),
            'old_values' => $this->sanitize($oldValues),
            'new_values' => $this->sanitize($newValues),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            'correlation_id' => (string) Str::uuid(),
        ]);
    }

    public function sanitize(array $values): array
    {
        foreach (self::SENSITIVE_KEYS as $key) {
            Arr::forget($values, $key);
        }

        return $values;
    }
}
