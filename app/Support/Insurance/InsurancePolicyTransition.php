<?php

namespace App\Support\Insurance;

use App\Enums\InsurancePolicyStatus;
use Illuminate\Support\Facades\DB;

class InsurancePolicyTransition
{
    public static function allow(InsurancePolicyStatus|string $from, InsurancePolicyStatus|string $to): void
    {
        $from = $from instanceof InsurancePolicyStatus ? $from->value : $from;
        $to = $to instanceof InsurancePolicyStatus ? $to->value : $to;
        DB::statement("select set_config('rentfleet.insurance_policy_transition', ?, true)", ["{$from}->{$to}"]);
    }
}
