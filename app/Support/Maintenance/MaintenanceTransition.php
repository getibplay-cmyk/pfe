<?php

namespace App\Support\Maintenance;

use Illuminate\Support\Facades\DB;
use LogicException;

class MaintenanceTransition
{
    public static function allow(string $from, string $to): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Une transition de maintenance exige une transaction active.');
        }

        DB::statement("SELECT set_config('rentfleet.maintenance_transition', ?, true)", [$from.'->'.$to]);
    }
}
