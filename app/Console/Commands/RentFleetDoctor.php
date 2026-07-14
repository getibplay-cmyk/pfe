<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class RentFleetDoctor extends Command
{
    protected $signature = 'rentfleet:doctor
                            {--json : Retourner un rapport JSON}
                            {--production : Exiger les réglages de production}';

    protected $description = 'Vérifie les prérequis de fonctionnement de RentFleet sans modifier les données.';

    /** @var array<int, array{name: string, status: string, detail: string}> */
    private array $checks = [];

    public function handle(): int
    {
        $this->checkEnvironment();
        $this->checkRuntime();
        $this->checkDatabase();
        $this->checkMigrations();
        $this->checkStorageAndBuild();
        $this->checkWorkers();
        $this->checkDatabaseInvariants();
        $this->checkReferenceData();

        if ($this->option('json')) {
            $this->line(json_encode([
                'status' => $this->hasFailures() ? 'error' : 'ok',
                'checks' => $this->checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Contrôle', 'État', 'Détail'], $this->checks);
        }

        return $this->hasFailures() ? self::FAILURE : self::SUCCESS;
    }

    private function checkEnvironment(): void
    {
        $production = app()->environment('production');
        $this->add('Environnement', $production ? 'pass' : ($this->option('production') ? 'fail' : 'warn'), app()->environment());
        $this->add(
            'Mode debug',
            $production && config('app.debug') ? 'fail' : 'pass',
            config('app.debug') ? 'activé' : 'désactivé'
        );
    }

    private function checkRuntime(): void
    {
        $this->add('PHP', version_compare(PHP_VERSION, '8.5.0', '>=') ? 'pass' : 'fail', PHP_VERSION);
        $missing = array_values(array_filter(['pdo_pgsql', 'pgsql'], fn (string $extension) => ! extension_loaded($extension)));
        $this->add('Extensions PostgreSQL', $missing === [] ? 'pass' : 'fail', $missing === [] ? 'pdo_pgsql, pgsql' : 'manquantes: '.implode(', ', $missing));
    }

    private function checkDatabase(): void
    {
        try {
            $driver = DB::connection()->getDriverName();
            $server = DB::selectOne("select current_setting('server_version') as version");
            $this->add('Connexion PostgreSQL', $driver === 'pgsql' ? 'pass' : 'fail', $driver.' '.$server->version);
        } catch (Throwable) {
            $this->add('Connexion PostgreSQL', 'fail', 'indisponible');
        }
    }

    private function checkMigrations(): void
    {
        try {
            $files = collect(File::files(database_path('migrations')))->map(fn ($file) => $file->getFilenameWithoutExtension())->sort()->values();
            $ran = DB::table('migrations')->pluck('migration')->sort()->values();
            $pending = $files->diff($ran);
            $this->add('Migrations', $pending->isEmpty() ? 'pass' : 'fail', $pending->isEmpty() ? $ran->count().' appliquées' : $pending->count().' en attente');
        } catch (Throwable) {
            $this->add('Migrations', 'fail', 'état non lisible');
        }
    }

    private function checkStorageAndBuild(): void
    {
        $privatePath = storage_path('app/private');
        $privateReady = is_dir($privatePath) && is_writable($privatePath);
        $this->add('Stockage privé', $privateReady ? 'pass' : 'fail', $privateReady ? 'accessible en écriture' : 'absent ou non inscriptible');
        $cachePath = base_path('bootstrap/cache');
        $cacheReady = is_dir($cachePath) && is_writable($cachePath);
        $this->add('Cache bootstrap', $cacheReady ? 'pass' : 'fail', $cacheReady ? 'accessible en écriture' : 'absent ou non inscriptible');
        $this->add('Build frontend', File::exists(public_path('build/manifest.json')) ? 'pass' : 'fail', 'public/build/manifest.json');
    }

    private function checkWorkers(): void
    {
        $this->add('Queue', config('queue.default') === 'database' ? 'pass' : 'warn', (string) config('queue.default'));

        try {
            $events = app(Schedule::class)->events();
            $scheduled = collect($events)->contains(fn ($event) => str_contains((string) $event->command, 'reservations:expire-pending'));
            $this->add('Scheduler', $scheduled ? 'pass' : 'fail', $scheduled ? 'expiration des réservations planifiée' : 'commande attendue absente');
        } catch (Throwable) {
            $this->add('Scheduler', 'fail', 'état non lisible');
        }
    }

    private function checkDatabaseInvariants(): void
    {
        try {
            $gist = DB::scalar("select count(*) from pg_constraint where conname = 'vehicle_blocks_no_active_overlap_excl'");
            $this->add('Contrainte GiST', (int) $gist === 1 ? 'pass' : 'fail', 'vehicle_blocks_no_active_overlap_excl');

            $triggers = DB::scalar("select count(*) from pg_trigger where not tgisinternal and tgname in ('contract_versions_prevent_locked_update', 'vehicle_inspections_prevent_completed_update', 'invoices_financial_immutability', 'payments_financial_immutability', 'rental_contracts_prevent_closed_before_finance')");
            $this->add('Immutabilité critique', (int) $triggers === 5 ? 'pass' : 'fail', ((int) $triggers).'/5 triggers');
        } catch (Throwable) {
            $this->add('Contraintes PostgreSQL', 'fail', 'état non lisible');
        }
    }

    private function checkReferenceData(): void
    {
        try {
            $roles = (int) DB::table('roles')->count();
            $permissions = (int) DB::table('permissions')->count();
            $referenceStatus = $roles > 0 && $permissions > 0
                ? 'pass'
                : (app()->environment('production') ? 'fail' : 'warn');
            $this->add('Rôles et permissions', $referenceStatus, $roles.' rôles, '.$permissions.' permissions');

            $tenants = (int) DB::table('tenants')->count();
            $this->add('Données de démonstration', $tenants >= 2 ? 'pass' : 'warn', $tenants.' tenant(s)');
        } catch (Throwable) {
            $this->add('Données de référence', 'fail', 'état non lisible');
        }
    }

    private function add(string $name, string $status, string $detail): void
    {
        $this->checks[] = compact('name', 'status', 'detail');
    }

    private function hasFailures(): bool
    {
        return collect($this->checks)->contains(fn (array $check) => $check['status'] === 'fail');
    }
}
