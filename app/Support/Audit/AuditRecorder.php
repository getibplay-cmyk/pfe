<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditRecorder
{
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password', 'token', 'secret', 'api_key', 'authorization', 'cookie',
        'card_number', 'cvv', 'cvc', 'identity_number', 'licence_number',
        'policy_number', 'insurer_reference', 'document_content',
    ];

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
            'correlation_id' => (string) ($request->attributes->get('correlation_id') ?: Str::uuid()),
        ]);
    }

    public function sanitize(array $values): array
    {
        $sanitized = [];

        foreach ($values as $key => $value) {
            $normalizedKey = Str::lower((string) $key);
            if (collect(self::SENSITIVE_KEY_FRAGMENTS)->contains(
                fn (string $fragment) => str_contains($normalizedKey, $fragment)
            )) {
                continue;
            }

            $sanitized[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $sanitized;
    }
}
