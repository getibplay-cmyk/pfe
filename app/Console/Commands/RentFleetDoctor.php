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
        $this->checkReportingIntegrity();
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
            $reservationScheduled = collect($events)->contains(fn ($event) => str_contains((string) $event->command, 'reservations:expire-pending'));
            $insuranceScheduled = collect($events)->contains(fn ($event) => str_contains((string) $event->command, 'insurance:expire-policies'));
            $scheduled = $reservationScheduled && $insuranceScheduled;
            $this->add('Scheduler', $scheduled ? 'pass' : 'fail', $scheduled ? 'expirations réservations et assurances planifiées' : 'une commande d’expiration attendue est absente');
        } catch (Throwable) {
            $this->add('Scheduler', 'fail', 'état non lisible');
        }
    }

    private function checkDatabaseInvariants(): void
    {
        try {
            $gist = DB::scalar("select count(*) from pg_constraint where conname = 'vehicle_blocks_no_active_overlap_excl'");
            $this->add('Contrainte GiST', (int) $gist === 1 ? 'pass' : 'fail', 'vehicle_blocks_no_active_overlap_excl');

            $triggers = DB::scalar("select count(*) from pg_trigger where not tgisinternal and tgname in ('contract_versions_prevent_locked_update', 'vehicle_inspections_prevent_completed_update', 'invoices_financial_immutability', 'payments_financial_immutability', 'rental_contracts_prevent_closed_before_finance', 'expenses_terminal_immutability', 'maintenance_histories_append_only', 'maintenance_orders_cycle_immutability')");
            $this->add('Immutabilité critique', (int) $triggers === 8 ? 'pass' : 'fail', ((int) $triggers).'/8 triggers');

            $maintenanceIndexes = DB::scalar("select count(*) from pg_indexes where indexname in ('vehicle_blocks_one_per_maintenance_unique', 'expenses_one_per_maintenance_unique')");
            $this->add('Unicité maintenance', (int) $maintenanceIndexes === 2 ? 'pass' : 'fail', ((int) $maintenanceIndexes).'/2 index uniques');

            $insuranceGist = DB::scalar("select count(*) from pg_constraint where conname = 'insurance_policies_no_active_overlap_excl'");
            $this->add('Exclusion polices actives', (int) $insuranceGist === 1 ? 'pass' : 'fail', 'insurance_policies_no_active_overlap_excl');
            $insuranceTriggers = DB::scalar("select count(*) from pg_trigger where not tgisinternal and tgname in ('insurance_companies_lifecycle','insurance_policies_cycle_immutability','insurance_policy_histories_append_only','insurance_coverages_draft_only','insurance_claims_incident_integrity')");
            $this->add('Intégrité assurance', (int) $insuranceTriggers === 5 ? 'pass' : 'fail', ((int) $insuranceTriggers).'/5 triggers');
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

    private function checkReportingIntegrity(): void
    {
        try {
            $periods = (int) DB::scalar(<<<'SQL'
                SELECT
                    (SELECT COUNT(*) FROM reservations WHERE starts_at >= ends_at)
                  + (SELECT COUNT(*) FROM rental_contracts WHERE expected_start_at >= expected_return_at)
                  + (SELECT COUNT(*) FROM vehicle_blocks WHERE starts_at >= ends_at)
                SQL);
            $this->add('Périodes du reporting', $periods === 0 ? 'pass' : 'fail', $periods.' période(s) invalide(s)');

            $allocationMismatches = (int) DB::scalar(<<<'SQL'
                SELECT COUNT(*)
                FROM payment_allocations a
                JOIN payments p ON p.id = a.payment_id
                JOIN invoices i ON i.id = a.invoice_id
                WHERE a.tenant_id <> p.tenant_id OR a.tenant_id <> i.tenant_id
                   OR a.agency_id <> p.agency_id OR a.agency_id <> i.agency_id
                   OR a.customer_id <> p.customer_id OR a.customer_id <> i.customer_id
                   OR a.currency <> p.currency OR a.currency <> i.currency
                SQL);
            $this->add('Allocations financières', $allocationMismatches === 0 ? 'pass' : 'fail', $allocationMismatches.' allocation(s) hors périmètre ou cross-devise');

            $invalidBlocks = (int) DB::scalar(<<<'SQL'
                SELECT COUNT(*)
                FROM vehicle_blocks b
                LEFT JOIN vehicles v ON v.id = b.vehicle_id AND v.tenant_id = b.tenant_id AND v.agency_id = b.agency_id
                WHERE b.status = 'active' AND (
                    b.starts_at >= b.ends_at OR b.released_at IS NOT NULL OR v.id IS NULL OR v.deleted_at IS NOT NULL
                    OR v.operational_status IN ('out_of_service', 'archived')
                    OR (b.block_type = 'reservation' AND b.reservation_id IS NULL)
                    OR (b.block_type = 'contract' AND b.rental_contract_id IS NULL)
                    OR (b.block_type = 'maintenance' AND b.maintenance_order_id IS NULL)
                    OR (b.block_type = 'manual' AND (b.reservation_id IS NOT NULL OR b.rental_contract_id IS NOT NULL OR b.maintenance_order_id IS NOT NULL))
                )
                SQL);
            $this->add('Blocs actifs du reporting', $invalidBlocks === 0 ? 'pass' : 'fail', $invalidBlocks.' bloc(s) actif(s) invalide(s)');

            $requiredIndexes = [
                'reservations_reporting_created_idx',
                'reservation_status_histories_reporting_events_idx',
                'rental_contracts_reporting_returns_idx',
                'vehicle_blocks_reporting_period_idx',
                'invoices_reporting_issued_idx',
                'payments_reporting_posted_idx',
                'deposit_transactions_reporting_occurred_idx',
                'expenses_reporting_date_idx',
                'maintenance_orders_reporting_schedule_idx',
                'insurance_claims_reporting_open_idx',
                'documents_reporting_expiry_idx',
                'drivers_reporting_licence_expiry_idx',
            ];
            $indexes = (int) DB::table('pg_indexes')->whereIn('indexname', $requiredIndexes)->count();
            $this->add('Index du reporting', $indexes === count($requiredIndexes) ? 'pass' : 'fail', $indexes.'/'.count($requiredIndexes).' présents');
        } catch (Throwable) {
            $this->add('Cohérence du reporting', 'fail', 'état non lisible');
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
