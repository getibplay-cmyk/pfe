<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Auth\PasswordHashInspector;
use Illuminate\Console\Command;

class AuditPasswordHashesCommand extends Command
{
    protected $signature = 'rentfleet:audit-password-hashes
        {--email= : Limiter le contrôle à une adresse e-mail précise}';

    protected $description = 'Compte les empreintes de mot de passe compatibles sans les afficher';

    public function handle(PasswordHashInspector $inspector): int
    {
        $query = User::query()->select(['id', 'password'])->orderBy('id');
        $email = trim((string) $this->option('email'));
        if ($email !== '') {
            $query->where('email', $email);
        }

        $compatible = 0;
        $incompatible = 0;

        $query->chunkById(200, function ($users) use ($inspector, &$compatible, &$incompatible): void {
            foreach ($users as $user) {
                if ($inspector->isCompatible($user->getAuthPassword())) {
                    $compatible++;
                } else {
                    $incompatible++;
                }
            }
        });

        $this->components->info('Audit terminé sans modification.');
        $this->line('Pilote attendu : '.$inspector->expectedDriver());
        $this->line('Comptes compatibles : '.$compatible);
        $this->line('Comptes incompatibles : '.$incompatible);
        $this->line('Comptes contrôlés : '.($compatible + $incompatible));

        return $incompatible === 0 ? self::SUCCESS : self::FAILURE;
    }
}
