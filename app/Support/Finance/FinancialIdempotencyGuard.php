<?php

namespace App\Support\Finance;

use App\Support\Pricing\DecimalMoney;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialIdempotencyGuard
{
    public function __construct(private readonly TenantContext $context) {}

    public function lock(string $key): void
    {
        DB::selectOne(
            'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
            [$this->context->tenantId().':'.$key],
        );
    }

    public function assertSameOperation(Model $existing, array $expected): void
    {
        foreach ($expected as $field => $value) {
            if ($this->normalize($field, $existing->getAttribute($field)) !== $this->normalize($field, $value)) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'Cette clé d’idempotence est déjà associée à une opération métier différente.',
                ]);
            }
        }
    }

    private function normalize(string $field, mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if ($value === null) {
            return null;
        }

        if ($field === 'amount') {
            return DecimalMoney::toMinorUnits((string) $value);
        }

        if ($field === 'currency') {
            return strtoupper(trim((string) $value));
        }

        if ($field === 'tenant_id' || $field === 'agency_id' || str_ends_with($field, '_id')) {
            return (int) $value;
        }

        return (string) $value;
    }
}
