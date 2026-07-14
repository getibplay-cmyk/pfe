<?php

namespace App\Actions\Reservations;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class GenerateReservationNumber
{
    public function handle(?int $year = null): string
    {
        $year ??= now(config('reservations.display_timezone'))->year;
        $row = DB::selectOne(
            'INSERT INTO reservation_number_counters (tenant_id, year, last_number) VALUES (?, ?, 1) ON CONFLICT (tenant_id, year) DO UPDATE SET last_number = reservation_number_counters.last_number + 1 RETURNING last_number',
            [app(TenantContext::class)->tenantId(), $year],
        );

        return sprintf('RES-%d-%06d', $year, $row->last_number);
    }
}
