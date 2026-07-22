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
        $this->info(sprintf('%d tenant(s) contrôlé(s), %d notification(s) créée(s).', $result['tenants'], $result['created']));

        return self::SUCCESS;
    }
}
