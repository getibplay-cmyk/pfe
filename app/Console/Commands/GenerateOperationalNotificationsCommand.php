<?php

namespace App\Console\Commands;

use App\Support\Notifications\GenerateOperationalNotifications;
use Illuminate\Console\Command;

class GenerateOperationalNotificationsCommand extends Command
{
    protected $signature = 'notifications:generate-operational';

    protected $description = 'Génère de manière idempotente les notifications opérationnelles internes';

    public function handle(GenerateOperationalNotifications $generator): int
    {
        $result = $generator->handle();
        $this->info(sprintf(
            '%d entreprise(s) contrôlée(s) : %d créée(s), %d mise(s) à jour, %d résolue(s), %d réactivée(s).',
            $result['tenants'],
            $result['created'],
            $result['updated'],
            $result['resolved'],
            $result['reactivated'],
        ));

        return self::SUCCESS;
    }
}
