<?php

namespace App\Console\Commands;

use App\Actions\Insurance\ExpireInsurancePolicies;
use Illuminate\Console\Command;

class ExpireInsurancePoliciesCommand extends Command
{
    protected $signature = 'insurance:expire-policies';

    protected $description = 'Expire les polices actives dont la période de couverture est terminée';

    public function handle(ExpireInsurancePolicies $action): int
    {
        $count = $action->handle();
        $this->info("{$count} police(s) expirée(s).");

        return self::SUCCESS;
    }
}
