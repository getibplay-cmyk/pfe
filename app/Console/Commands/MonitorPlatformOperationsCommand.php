<?php

namespace App\Console\Commands;

use App\Actions\Operations\RefreshPlatformOperationalIncidents;
use Illuminate\Console\Command;

final class MonitorPlatformOperationsCommand extends Command
{
    protected $signature = 'operations:monitor-platform {--json : Retourner un résumé JSON}';

    protected $description = 'Contrôle les signaux opérationnels et actualise les incidents de la plateforme';

    public function handle(RefreshPlatformOperationalIncidents $refresh): int
    {
        $summary = $refresh->handle();

        if ($this->option('json')) {
            $this->line((string) json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->info(sprintf(
                'Supervision terminée : %d contrôle(s), %d incident(s) ouvert(s), %d résolu(s).',
                $summary['checked'],
                $summary['open'],
                $summary['resolved'],
            ));
        }

        return self::SUCCESS;
    }
}
