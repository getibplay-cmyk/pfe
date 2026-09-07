<?php

namespace App\Support\PlatformBilling;

use App\Enums\PlatformBilling\SaasBillingInterval;
use App\Models\PlatformBilling\SaasSubscription;
use Carbon\CarbonImmutable;

final class SaasBillingPeriod
{
    public function end(SaasSubscription $subscription, CarbonImmutable $start): CarbonImmutable
    {
        return $subscription->billing_interval === SaasBillingInterval::Annual
            ? $start->addYearNoOverflow()
            : $start->addMonthNoOverflow();
    }
}
