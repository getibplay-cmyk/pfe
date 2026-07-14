<?php

namespace App\Console\Commands;

use App\Actions\Reservations\ExpirePendingReservations;
use Illuminate\Console\Command;

class ExpirePendingReservationsCommand extends Command
{
    protected $signature = 'reservations:expire-pending';

    protected $description = 'Expire les réservations pending arrivées à échéance';

    public function handle(ExpirePendingReservations $action): int
    {
        $count = $action->handle();
        $this->info("{$count} réservation(s) expirée(s).");

        return self::SUCCESS;
    }
}
