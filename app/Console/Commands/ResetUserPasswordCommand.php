<?php

namespace App\Console\Commands;

use App\Actions\Auth\ResetUserPasswordAdministratively;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class ResetUserPasswordCommand extends Command
{
    protected $signature = 'rentfleet:reset-user-password
        {email? : Adresse e-mail exacte du compte}
        {--tenant= : Slug exact du tenant du compte métier}
        {--platform : Cibler exclusivement un administrateur plateforme}';

    protected $description = 'Réinitialise un mot de passe dans un périmètre administratif explicite';

    public function handle(ResetUserPasswordAdministratively $action): int
    {
        $tenantSlug = trim((string) $this->option('tenant'));
        $platform = (bool) $this->option('platform');

        if (($tenantSlug === '' && ! $platform) || ($tenantSlug !== '' && $platform)) {
            $this->components->error(
                'Précisez exactement --tenant=SLUG ou --platform, sans combiner les deux.'
            );

            return self::FAILURE;
        }

        $email = trim((string) ($this->argument('email') ?: $this->ask('Adresse e-mail')));
        $user = $platform
            ? User::query()
                ->whereNull('tenant_id')
                ->whereNull('agency_id')
                ->where('is_platform_admin', true)
                ->where('email', $email)
                ->first()
            : $this->tenantUser($tenantSlug, $email);

        if (! $user) {
            $this->components->error('Aucun compte ne correspond au périmètre administratif indiqué.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Nouveau mot de passe');
        $confirmation = (string) $this->secret('Confirmation du nouveau mot de passe');
        $validator = Validator::make([
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $action->handle($user, $password);
        $this->components->info(
            'Mot de passe réinitialisé. Le compte devra le remplacer à sa prochaine connexion.'
        );

        return self::SUCCESS;
    }

    private function tenantUser(string $tenantSlug, string $email): ?User
    {
        $tenantId = Tenant::query()->where('slug', $tenantSlug)->value('id');
        if (! $tenantId) {
            return null;
        }

        return User::query()
            ->where('tenant_id', $tenantId)
            ->where('is_platform_admin', false)
            ->where('email', $email)
            ->first();
    }
}
