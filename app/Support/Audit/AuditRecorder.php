<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\Tenant;
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

        $tenantId = $subject instanceof Tenant
            ? $subject->getKey()
            : $subject->getAttribute('tenant_id');
        $tenantId ??= $context->hasTenant() ? $context->tenantId() : null;
        $subjectKey = $subject->getKey();
        $uuidSubject = is_string($subjectKey) && Str::isUuid($subjectKey);

        return AuditLog::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'agency_id' => $subject->getAttribute('agency_id') ?? $context->agencyId(),
            'user_id' => $request->user()?->getKey(),
            'action' => $action,
            'auditable_type' => $subject::class,
            'auditable_id' => $uuidSubject ? null : $subjectKey,
            ...($uuidSubject ? ['auditable_uuid' => $subjectKey] : []),
            'old_values' => $this->sanitize($oldValues),
            'new_values' => $this->sanitize($newValues),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            'correlation_id' => (string) ($request->attributes->get('correlation_id') ?: Str::uuid()),
        ]);
    }

    public function sanitize(array $values): array
    {
        $remaining = 300;

        return $this->sanitizeBounded($values, 0, $remaining);
    }

    private function sanitizeBounded(array $values, int $depth, int &$remaining): array
    {
        if ($depth >= 6) {
            return ['truncated' => true];
        }
        $sanitized = [];

        foreach (array_slice($values, 0, 100, true) as $key => $value) {
            if ($remaining-- <= 0) {
                $sanitized['truncated'] = true;
                break;
            }
            $normalizedKey = Str::lower((string) $key);
            if (collect(self::SENSITIVE_KEY_FRAGMENTS)->contains(
                fn (string $fragment) => str_contains($normalizedKey, $fragment)
            )) {
                continue;
            }

            $safeKey = is_string($key) ? Str::limit(preg_replace('/[\x00-\x1F\x7F]/', '', $key), 128, '') : $key;
            $sanitized[$safeKey] = match (true) {
                is_array($value) => $this->sanitizeBounded($value, $depth + 1, $remaining),
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
                is_string($value) => Str::limit(preg_replace('/[\x00-\x1F\x7F]/', ' ', $value), 2000, ''),
                is_scalar($value), $value === null => $value,
                default => '[unsupported value]',
            };
        }

        return $sanitized;
    }
}
