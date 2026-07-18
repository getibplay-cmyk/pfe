<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

class VerifyRestoredDataCommand extends Command
{
    protected $signature = 'rentfleet:verify-restored-data';

    protected $description = 'Vérifie sans les afficher les valeurs chiffrées d’une restauration isolée';

    public function handle(): int
    {
        if (DB::connection()->getDatabaseName() !== 'rentfleet_restore_test') {
            $this->error('Vérification refusée : la base doit être rentfleet_restore_test.');

            return self::FAILURE;
        }

        if (! app()->environment('restore-verification')) {
            $this->error('Vérification refusée : APP_ENV doit être restore-verification.');

            return self::FAILURE;
        }

        $privateRoot = realpath((string) config('filesystems.disks.local.root'));
        $liveRoot = realpath(storage_path('app/private'));
        if ($privateRoot === false || $privateRoot === $liveRoot) {
            $this->error('Vérification refusée : la racine documentaire doit être restaurée et isolée.');

            return self::FAILURE;
        }

        $columns = [
            'customers' => 'identity_number_encrypted',
            'drivers' => 'licence_number_encrypted',
            'insurance_policies' => 'policy_number_encrypted',
            'insurance_claims' => 'insurer_reference_encrypted',
        ];
        $verified = 0;

        try {
            foreach ($columns as $table => $column) {
                DB::table($table)->whereNotNull($column)->orderBy('id')->pluck($column)->each(function (mixed $ciphertext) use (&$verified): void {
                    Crypt::decryptString((string) $ciphertext);
                    $verified++;
                });
            }
        } catch (Throwable) {
            $this->error('Au moins une valeur chiffrée restaurée ne peut pas être déchiffrée.');

            return self::FAILURE;
        }

        if ($verified === 0) {
            $this->error('Aucune valeur chiffrée n’a pu être soumise au contrôle.');

            return self::FAILURE;
        }

        $this->info($verified.' valeur(s) chiffrée(s) vérifiée(s) sans divulgation.');

        return self::SUCCESS;
    }
}
