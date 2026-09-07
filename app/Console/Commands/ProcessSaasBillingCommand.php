<?php

namespace App\Console\Commands;

use App\Actions\PlatformBilling\ProcessSaasBilling;
use Illuminate\Console\Command;

class ProcessSaasBillingCommand extends Command
{
    protected $signature = 'saas:process-billing';

    protected $description = 'Émettre les renouvellements consentis et expirer les changements non réglés, sans prélèvement bancaire';

    public function handle(ProcessSaasBilling $billing): int
    {
        $this->info($billing->handle().' abonnements contrôlés. Aucun prélèvement bancaire.');

        return self::SUCCESS;
    }
}
