<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecordSchedulerHeartbeatCommand extends Command
{
    protected $signature = 'operations:scheduler-heartbeat';

    protected $description = 'Enregistre la dernière exécution réussie du scheduler sans donnée sensible';

    public function handle(): int
    {
        DB::table('operational_heartbeats')->upsert([
            [
                'component' => (string) config('operations.scheduler.heartbeat_component'),
                'last_succeeded_at' => now(),
            ],
        ], ['component'], ['last_succeeded_at']);

        $this->info('Heartbeat du scheduler enregistré.');

        return self::SUCCESS;
    }
}
