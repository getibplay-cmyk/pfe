<?php

namespace App\Actions\Rentals;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerateBusinessNumber
{
    public function handle(string $type, ?int $year = null): string
    {
        $prefix = match ($type) {
            'contract' => 'CTR', 'damage' => 'DMG', default => throw ValidationException::withMessages(['number' => 'Type de numérotation inconnu.'])
        };
        $year ??= now(config('reservations.display_timezone'))->year;
        $row = DB::selectOne('INSERT INTO business_number_counters (tenant_id, document_type, year, last_number) VALUES (?, ?, ?, 1) ON CONFLICT (tenant_id, document_type, year) DO UPDATE SET last_number = business_number_counters.last_number + 1 RETURNING last_number', [app(TenantContext::class)->tenantId(), $type, $year]);

        return sprintf('%s-%d-%06d', $prefix, $year, $row->last_number);
    }
}
