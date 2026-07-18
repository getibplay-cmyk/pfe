<?php

namespace App\Support\Insurance;

use Illuminate\Support\Facades\DB;

class InsuranceCompanyTransition
{
    public static function allow(bool $from, bool $to): void
    {
        DB::statement("select set_config('rentfleet.insurance_company_transition', ?, true)", [($from ? 'active' : 'inactive').'->'.($to ? 'active' : 'inactive')]);
    }
}
