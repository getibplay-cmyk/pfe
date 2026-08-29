<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Models\Tenant;
use App\Support\Testing\TestDatabaseGuard;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RentFleetDemoV1HistoricalSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

class InstallDemoDatabaseCommand extends Command
{
    protected $signature = 'rentfleet:demo:install
                            {--expect-database=rentfleet_demo : Exiger le nom exact de la base cible}';

    protected $description = 'Installe sans destruction le schéma et les données fictives dans une base de démonstration vide.';

    /** @var list<string> */
    private const BUSINESS_TABLES = [
        'tenants',
        'agencies',
        'users',
        'vehicle_categories',
        'vehicles',
        'customers',
        'reservations',
        'rental_contracts',
        'invoices',
    ];

    public function handle(): int
    {
        $expectedDatabase = trim((string) $this->option('expect-database'));

        if (! $this->targetIsAllowed($expectedDatabase)) {
            $this->components->error(
                'Installation refusée : utiliser la base exacte rentfleet_demo. '
                .'Seuls les tests automatisés peuvent cibler rentfleet_test.'
            );

            return self::FAILURE;
        }

        if (config('database.default') !== 'pgsql') {
            $this->components->error('Installation refusée : PostgreSQL est l’unique moteur pris en charge.');

            return self::FAILURE;
        }

        try {
            $configuredDatabase = trim((string) config('database.connections.pgsql.database'));
            $resolvedDatabase = trim((string) (DB::selectOne('select current_database() as database_name')?->database_name ?? ''));
        } catch (Throwable) {
            $this->components->error('Connexion PostgreSQL impossible. Vérifiez la base dédiée et la configuration locale.');

            return self::FAILURE;
        }

        if ($configuredDatabase !== $expectedDatabase || $resolvedDatabase !== $expectedDatabase) {
            $this->components->error(
                'Installation refusée : la base configurée et la base PostgreSQL résolue doivent toutes deux être '
                .$expectedDatabase.'.'
            );

            return self::FAILURE;
        }

        if (! filled(config('app.key'))) {
            $this->components->error('APP_KEY est absente. Exécutez d’abord : php artisan key:generate');

            return self::FAILURE;
        }

        if (! $this->demoPasswordIsValid()) {
            $this->components->error(
                'DEMO_PASSWORD doit respecter la politique locale : 12 caractères minimum, majuscules, '
                .'minuscules et chiffres. Conservez-le uniquement dans un gestionnaire de secrets ou le fichier .env local.'
            );

            return self::FAILURE;
        }

        $this->components->info('Cible vérifiée : '.$expectedDatabase.'. Exécution non destructive des migrations.');
        try {
            $migrationExitCode = $this->call('migrate', ['--force' => true, '--no-interaction' => true]);
        } catch (Throwable) {
            $migrationExitCode = self::FAILURE;
        }

        if ($migrationExitCode !== self::SUCCESS) {
            $this->components->error('Les migrations ont échoué. Aucune tentative de seeding n’a été lancée.');

            return self::FAILURE;
        }

        if ($this->demoIsComplete()) {
            $this->components->info('rentfleet_demo_v1 est déjà installé ; aucune donnée n’a été dupliquée.');
            $this->renderSummary();

            return self::SUCCESS;
        }

        $occupiedTables = $this->occupiedBusinessTables();
        if ($occupiedTables !== []) {
            $this->components->error(
                'Seeding refusé : la base contient déjà des données métier dans '.implode(', ', $occupiedTables).'. '
                .'Utilisez une nouvelle base rentfleet_demo vide ou restaurez une sauvegarde contrôlée.'
            );

            return self::FAILURE;
        }

        $this->components->info('Schéma prêt. Installation du jeu fictif complet et déterministe.');
        try {
            $seedingExitCode = $this->call('db:seed', [
                '--class' => DatabaseSeeder::class,
                '--force' => true,
                '--no-interaction' => true,
            ]);
        } catch (Throwable) {
            $seedingExitCode = self::FAILURE;
        }

        if ($seedingExitCode !== self::SUCCESS) {
            $this->components->error(
                'Le seeding a échoué. Ne relancez pas sur une base partiellement remplie ; recréez la base dédiée vide.'
            );

            return self::FAILURE;
        }

        if (! $this->demoIsComplete()) {
            $this->components->error('Installation incomplète : les volumes attendus de rentfleet_demo_v1 sont absents.');

            return self::FAILURE;
        }

        $this->components->info('Base de démonstration installée avec succès.');
        $this->renderSummary();

        return self::SUCCESS;
    }

    private function targetIsAllowed(string $expectedDatabase): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        if ($expectedDatabase === 'rentfleet_demo') {
            return true;
        }

        return app()->environment('testing')
            && $expectedDatabase === TestDatabaseGuard::REQUIRED_DATABASE;
    }

    private function demoPasswordIsValid(): bool
    {
        if (app()->environment('testing')) {
            return true;
        }

        return ! Validator::make(
            ['password' => env('DEMO_PASSWORD')],
            ['password' => ['required', Password::defaults()]],
        )->fails();
    }

    private function demoIsComplete(): bool
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasTable('reservations')) {
            return false;
        }

        $tenantSlugs = Tenant::query()
            ->whereIn('slug', ['atlas-location-demo', 'rif-mobilite-demo'])
            ->pluck('slug')
            ->all();
        $historicalReservations = Reservation::withoutGlobalScopes()
            ->where('reservation_number', 'like', 'RES-DEMO-V1-%')
            ->count();
        $historicalContracts = DB::table('rental_contracts')
            ->where('contract_number', 'like', 'CTR-DEMO-V1-%')
            ->count();
        $historicalInvoices = DB::table('invoices')
            ->where('invoice_number', 'like', 'INV-DEMO-V1-%')
            ->count();

        return count($tenantSlugs) === 2
            && $historicalReservations === RentFleetDemoV1HistoricalSeeder::HISTORICAL_CONTRACTS
            && $historicalContracts === RentFleetDemoV1HistoricalSeeder::HISTORICAL_CONTRACTS
            && $historicalInvoices === RentFleetDemoV1HistoricalSeeder::PAID_CONTRACTS
                + RentFleetDemoV1HistoricalSeeder::PARTIALLY_PAID_CONTRACTS;
    }

    /** @return list<string> */
    private function occupiedBusinessTables(): array
    {
        return array_values(array_filter(
            self::BUSINESS_TABLES,
            fn (string $table): bool => Schema::hasTable($table) && DB::table($table)->exists(),
        ));
    }

    private function renderSummary(): void
    {
        $this->table(['Donnée', 'Volume'], [
            ['Tenants fictifs', DB::table('tenants')->count()],
            ['Agences', DB::table('agencies')->count()],
            ['Véhicules', DB::table('vehicles')->count()],
            ['Clients', DB::table('customers')->count()],
            ['Réservations historiques v1', DB::table('reservations')->where('reservation_number', 'like', 'RES-DEMO-V1-%')->count()],
            ['Contrats historiques v1', DB::table('rental_contracts')->where('contract_number', 'like', 'CTR-DEMO-V1-%')->count()],
            ['Factures historiques v1', DB::table('invoices')->where('invoice_number', 'like', 'INV-DEMO-V1-%')->count()],
        ]);

        $this->line('Contrôle final conseillé : php artisan rentfleet:doctor --expect-database=rentfleet_demo');
    }
}
