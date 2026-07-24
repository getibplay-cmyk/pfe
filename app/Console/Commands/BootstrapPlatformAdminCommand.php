<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Throwable;

class BootstrapPlatformAdminCommand extends Command
{
    protected $signature = 'rentfleet:bootstrap-platform-admin
        {--allow-additional : Autoriser explicitement un administrateur plateforme supplémentaire}';

    protected $description = 'Crée de manière sécurisée le premier administrateur de la plateforme';

    public function handle(AuditRecorder $audit): int
    {
        $additional = User::query()->where('is_platform_admin', true)->exists();
        if ($additional && ! $this->option('allow-additional')) {
            $this->components->error(
                'Un administrateur plateforme existe déjà. Aucun compte supplémentaire n’a été créé.'
            );

            return self::FAILURE;
        }

        if ($additional && ! $this->confirm(
            'Un administrateur existe déjà. Confirmez-vous la création explicite d’un administrateur supplémentaire ?',
            false,
        )) {
            $this->components->warn('Création annulée.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Nom de l’administrateur'));
        $email = trim((string) $this->ask('Adresse e-mail'));
        $password = (string) $this->secret('Mot de passe');
        $confirmation = (string) $this->secret('Confirmation du mot de passe');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($name, $email, $password, $additional, $audit): void {
                DB::select('SELECT pg_advisory_xact_lock(?)', [6067101]);

                if (! $additional && User::query()->where('is_platform_admin', true)->exists()) {
                    throw new \LogicException('Un administrateur plateforme existe déjà.');
                }

                $role = Role::query()
                    ->whereNull('tenant_id')
                    ->where('slug', 'platform-admin')
                    ->lockForUpdate()
                    ->first();

                if (! $role) {
                    $role = Role::forceCreate([
                        'tenant_id' => null,
                        'name' => 'Platform Admin',
                        'slug' => 'platform-admin',
                        'is_system' => true,
                        'is_active' => true,
                    ]);
                } else {
                    $role->forceFill(['is_system' => true, 'is_active' => true])->save();
                }

                $user = User::forceCreate([
                    'tenant_id' => null,
                    'agency_id' => null,
                    'role_id' => $role->id,
                    'name' => $name,
                    'email' => $email,
                    'email_verified_at' => now(),
                    'password' => Hash::make($password),
                    'is_platform_admin' => true,
                    'is_active' => true,
                    'must_change_password' => false,
                ]);

                $audit->record('platform.admin.bootstrapped', $user, [], [
                    'role_id' => $role->id,
                    'additional' => $additional,
                ]);
            });
        } catch (Throwable) {
            $this->components->error(
                'La création a échoué. Aucun administrateur plateforme n’a été ajouté.'
            );

            return self::FAILURE;
        }

        $this->components->info('Administrateur plateforme créé avec succès.');

        return self::SUCCESS;
    }
}
