<?php

namespace App\Console\Commands;

use App\Support\Intelligence\DemandForecasting\DemandForecastModelArtifact;
use App\Support\Intelligence\VehicleColor\VehicleColorModelArtifact;
use App\Support\Intelligence\VehicleDamage\VehicleDamageModelArtifact;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

class RentFleetDoctor extends Command
{
    protected $signature = 'rentfleet:doctor
                            {--json : Retourner un rapport JSON}
                            {--production : Exiger les réglages de production}
                            {--expect-database= : Exiger le nom exact d’une base sans afficher de secret}';

    protected $description = 'Vérifie les prérequis de fonctionnement de RentFleet sans modifier les données.';

    /** @var array<int, array{name: string, status: string, detail: string}> */
    private array $checks = [];

    public function handle(): int
    {
        $this->checks = [];
        $this->checkEnvironment();
        $this->checkProductionConfiguration();
        $this->checkRuntime();
        $this->checkDatabase();
        if (config('database.default') === 'pgsql') {
            $this->checkMigrations();
        } else {
            $this->add('Migrations', 'fail', 'non vérifiables sans PostgreSQL');
        }
        $this->checkStorageAndBuild();
        $this->checkWorkers();
        if (config('database.default') === 'pgsql') {
            $this->checkDatabaseInvariants();
            $this->checkReportingIntegrity();
            $this->checkReferenceData();
        }

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
        $productionRequired = $production || $this->option('production');
        $this->add('Environnement', $production ? 'pass' : ($this->option('production') ? 'fail' : 'warn'), app()->environment());
        $this->add(
            'Mode debug',
            $productionRequired && config('app.debug') ? 'fail' : 'pass',
            config('app.debug') ? 'activé' : 'désactivé'
        );
    }

    private function checkProductionConfiguration(): void
    {
        if (! app()->environment('production') && ! $this->option('production')) {
            return;
        }

        $url = (string) config('app.url');
        $this->add('URL de production', parse_url($url, PHP_URL_SCHEME) === 'https' ? 'pass' : 'fail', parse_url($url, PHP_URL_SCHEME) === 'https' ? 'HTTPS' : 'HTTPS requis');
        $this->add('Clé applicative', filled(config('app.key')) ? 'pass' : 'fail', filled(config('app.key')) ? 'configurée' : 'absente');
        $this->add('Base de données production', config('database.default') === 'pgsql' ? 'pass' : 'fail', (string) config('database.default'));

        $disk = (string) config('filesystems.default');
        $root = (string) config("filesystems.disks.{$disk}.root", '');
        $publicRoot = rtrim(str_replace('\\', '/', public_path()), '/').'/';
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/').'/';
        $privateDisk = $disk === 'local'
            && $root !== ''
            && ! str_starts_with(mb_strtolower($normalizedRoot), mb_strtolower($publicRoot))
            && config("filesystems.disks.{$disk}.serve") === false
            && config("filesystems.disks.{$disk}.visibility") !== 'public';
        $this->add('Stockage documentaire production', $privateDisk ? 'pass' : 'fail', $privateDisk ? 'privé et non servi' : 'disque privé requis');

        $sessionSecure = config('session.secure') === true
            && config('session.http_only') === true
            && in_array(config('session.same_site'), ['lax', 'strict'], true);
        $this->add('Cookies de session production', $sessionSecure ? 'pass' : 'fail', $sessionSecure ? 'secure, httpOnly, SameSite' : 'configuration insuffisante');
        $this->add('Cache partagé', config('cache.default') === 'database' ? 'pass' : 'fail', (string) config('cache.default'));

        $logChannel = (string) config('logging.default');
        $stackChannels = (array) config('logging.channels.stack.channels', []);
        $rotatingLogs = $logChannel === 'daily' || ($logChannel === 'stack' && in_array('daily', $stackChannels, true));
        $this->add('Rotation des logs', $rotatingLogs ? 'pass' : 'fail', $rotatingLogs ? 'daily' : 'canal daily requis');

        $mailScheme = config('mail.mailers.smtp.scheme');
        $this->add('Schéma SMTP', in_array($mailScheme, ['smtp', 'smtps'], true) ? 'pass' : 'fail', $mailScheme ?: 'absent');
    }

    private function checkRuntime(): void
    {
        $this->add('PHP', version_compare(PHP_VERSION, '8.5.0', '>=') ? 'pass' : 'fail', PHP_VERSION);
        $missing = array_values(array_filter(['pdo_pgsql', 'pgsql'], fn (string $extension) => ! extension_loaded($extension)));
        $this->add('Extensions PostgreSQL', $missing === [] ? 'pass' : 'fail', $missing === [] ? 'pdo_pgsql, pgsql' : 'manquantes: '.implode(', ', $missing));
        $pythonBinary = (string) config('intelligence.fleet_reallocation.python_binary');
        $runtimeScript = (string) config('intelligence.fleet_reallocation.runtime_script');
        $ortoolsConfigured = (bool) config('intelligence.fleet_reallocation.runtime_enabled')
            && $pythonBinary !== ''
            && File::exists($runtimeScript);
        $runtimeVersion = null;
        if ($ortoolsConfigured) {
            try {
                $probe = Process::path(base_path())
                    ->timeout(5)
                    ->env([
                        'APP_KEY' => false,
                        'DB_PASSWORD' => false,
                        'MAIL_PASSWORD' => false,
                        'AWS_SECRET_ACCESS_KEY' => false,
                        'INTELLIGENCE_EXPORT_HMAC_KEY' => false,
                        'DEMO_PASSWORD' => false,
                        'PGPASSWORD' => false,
                    ])
                    ->run([
                        $pythonBinary,
                        '-c',
                        'import importlib.metadata,sys; print(f\'{sys.version_info.major}.{sys.version_info.minor}|{importlib.metadata.version("ortools")}\')',
                    ]);
                if ($probe->successful()) {
                    $runtimeVersion = trim($probe->output());
                }
            } catch (Throwable) {
                // Le détail public reste volontairement limité aux versions attendues.
            }
        }
        $runtimeReady = $ortoolsConfigured && $runtimeVersion === '3.12|9.15.6755';
        $production = app()->environment('production') || $this->option('production');
        $this->add(
            'Runtime OR-Tools',
            $runtimeReady ? 'pass' : ($production ? 'fail' : 'warn'),
            $runtimeReady
                ? 'Python 3.12 · OR-Tools 9.15.6755 · script présent'
                : 'Python 3.12 et OR-Tools 9.15.6755 requis; vérifier INTELLIGENCE_PYTHON_BINARY',
        );

        $demandBinary = (string) config('intelligence.demand_forecasting.python_binary');
        $demandScript = (string) config('intelligence.demand_forecasting.runtime_script');
        $modelArtifact = app(DemandForecastModelArtifact::class);
        $demandConfigured = (bool) config('intelligence.demand_forecasting.runtime_enabled')
            && $demandBinary !== ''
            && File::exists($demandScript);
        $demandVersions = null;
        if ($demandConfigured) {
            try {
                $probe = Process::path(base_path())
                    ->timeout(8)
                    ->env([
                        'PYTHONDONTWRITEBYTECODE' => '1',
                        'APP_KEY' => false,
                        'DB_PASSWORD' => false,
                        'MAIL_PASSWORD' => false,
                        'AWS_SECRET_ACCESS_KEY' => false,
                        'INTELLIGENCE_EXPORT_HMAC_KEY' => false,
                        'DEMO_PASSWORD' => false,
                        'PGPASSWORD' => false,
                    ])
                    ->run([
                        $demandBinary,
                        '-c',
                        'import joblib,numpy,pandas,sklearn,sys; print(f\'{sys.version_info.major}.{sys.version_info.minor}|{numpy.__version__}|{pandas.__version__}|{sklearn.__version__}|{joblib.__version__}\')',
                    ]);
                if ($probe->successful()) {
                    $demandVersions = trim($probe->output());
                }
            } catch (Throwable) {
                // Les chemins et stderr du runtime ne sont jamais exposés par le doctor.
            }
        }
        $modelReady = $modelArtifact->configuredIsValid();
        $demandReady = $demandConfigured
            && $modelReady
            && $demandVersions === '3.12|2.0.2|2.2.2|1.6.1|1.5.3';
        $this->add(
            'Runtime HGB J5',
            $demandReady ? 'pass' : ($production ? 'fail' : 'warn'),
            $demandReady
                ? 'bundle authentique vérifié · Python 3.12 · numpy 2.0.2 · pandas 2.2.2 · scikit-learn 1.6.1 · joblib 1.5.3'
                : ($modelReady
                    ? 'bundle vérifié; environnement Python figé incomplet'
                    : 'bundle J5 privé exact absent ou empreinte/taille invalide'),
        );

        $this->checkVehicleDamageRuntime($production);
        $this->checkRentalUsageAnomalyRuntime($production);

        $colorEnabled = (bool) config('intelligence.vehicle_color_v8.enabled');
        if (! $colorEnabled) {
            $this->add(
                'Runtime couleur S7 v8',
                'warn',
                'désactivé par défaut; activation explicite requise après installation et contrôle',
            );

            return;
        }

        $colorBinary = (string) config('intelligence.vehicle_color_v8.python_binary');
        $colorScript = (string) config('intelligence.vehicle_color_v8.runtime_script');
        $colorSanitizer = (string) config('intelligence.vehicle_color_v8.image_sanitizer_script');
        $colorProvider = (string) config('intelligence.vehicle_color_v8.execution_provider');
        $colorArtifact = app(VehicleColorModelArtifact::class);
        $colorConfigured = $colorBinary !== ''
            && File::exists($colorScript)
            && File::exists($colorSanitizer)
            && in_array($colorProvider, ['CPUExecutionProvider', 'CUDAExecutionProvider'], true);
        $colorVersions = null;
        if ($colorConfigured) {
            try {
                $probe = Process::path(sys_get_temp_dir())
                    ->timeout(8)
                    ->env([
                        'PYTHONDONTWRITEBYTECODE' => '1',
                        'ORT_DISABLE_TELEMETRY_EVENTS' => '1',
                        'APP_KEY' => false,
                        'DB_PASSWORD' => false,
                        'MAIL_PASSWORD' => false,
                        'AWS_SECRET_ACCESS_KEY' => false,
                        'INTELLIGENCE_EXPORT_HMAC_KEY' => false,
                        'DEMO_PASSWORD' => false,
                        'PGPASSWORD' => false,
                    ])
                    ->run([
                        $colorBinary,
                        '-c',
                        'import importlib.metadata,numpy,onnxruntime,sys; provider=sys.argv[1]; print(f\'{sys.version_info.major}.{sys.version_info.minor}|{numpy.__version__}|{importlib.metadata.version("Pillow")}|{onnxruntime.__version__}|{int(provider in onnxruntime.get_available_providers())}\')',
                        $colorProvider,
                    ]);
                if ($probe->successful()) {
                    $colorVersions = trim($probe->output());
                }
            } catch (Throwable) {
                // Les chemins et stderr du runtime ne sont jamais exposés par le doctor.
            }
        }
        $colorArtifactReady = $colorArtifact->configuredIsValid();
        $colorReady = $colorConfigured
            && $colorArtifactReady
            && $colorVersions === '3.12|2.3.5|12.3.0|1.29.0|1';
        $this->add(
            'Runtime couleur S7 v8',
            $colorReady ? 'pass' : ($production ? 'fail' : 'warn'),
            $colorReady
                ? 'paire ONNX/métadonnées vérifiée · Python 3.12 · numpy 2.3.5 · Pillow 12.3.0 · ONNX Runtime 1.29.0 · fournisseur disponible'
                : ($colorArtifactReady
                    ? 'paire vérifiée; environnement Python ou fournisseur ONNX incomplet'
                    : 'paire privée ONNX/métadonnées absente ou empreinte/taille invalide'),
        );
    }

    private function checkVehicleDamageRuntime(bool $production): void
    {
        $enabled = (bool) config('intelligence.vehicle_damage_v1.enabled');
        $backend = (string) config('intelligence.vehicle_damage_v1.backend');
        $runtimeLabel = $backend === 'rtdetrv2_s'
            ? 'Runtime dommages RT-DETRv2-S'
            : 'Runtime dommages EfficientNetV2-S';
        if (! $enabled) {
            $this->add(
                $runtimeLabel,
                'warn',
                'désactivé par défaut; activation explicite requise après installation et contrôle',
            );

            return;
        }

        $binary = (string) config('intelligence.vehicle_damage_v1.python_binary');
        $script = (string) config('intelligence.vehicle_damage_v1.runtime_script');
        $sanitizer = (string) config('intelligence.vehicle_damage_v1.image_sanitizer_script');
        $provider = (string) config('intelligence.vehicle_damage_v1.execution_provider');
        $artifact = app(VehicleDamageModelArtifact::class);
        $configured = $binary !== ''
            && File::exists($script)
            && File::exists($sanitizer)
            && in_array($provider, ['CPUExecutionProvider', 'CUDAExecutionProvider'], true);
        $versions = null;
        if ($configured) {
            try {
                $probe = Process::path(sys_get_temp_dir())
                    ->timeout(8)
                    ->env([
                        'PYTHONDONTWRITEBYTECODE' => '1',
                        'ORT_DISABLE_TELEMETRY_EVENTS' => '1',
                        'APP_KEY' => false,
                        'DB_PASSWORD' => false,
                        'MAIL_PASSWORD' => false,
                        'AWS_SECRET_ACCESS_KEY' => false,
                        'INTELLIGENCE_EXPORT_HMAC_KEY' => false,
                        'DEMO_PASSWORD' => false,
                        'PGPASSWORD' => false,
                    ])
                    ->run([
                        $binary,
                        '-c',
                        'import importlib.metadata,numpy,onnxruntime,sys; provider=sys.argv[1]; print(f\'{sys.version_info.major}.{sys.version_info.minor}|{numpy.__version__}|{importlib.metadata.version("Pillow")}|{onnxruntime.__version__}|{int(provider in onnxruntime.get_available_providers())}\')',
                        $provider,
                    ]);
                if ($probe->successful()) {
                    $versions = trim($probe->output());
                }
            } catch (Throwable) {
                // Les chemins, empreintes et stderr du runtime ne sont jamais exposés.
            }
        }
        $artifactReady = $artifact->configuredIsValid();
        $ready = $configured
            && $artifactReady
            && $versions === '3.12|2.3.5|12.3.0|1.29.0|1';
        $this->add(
            $runtimeLabel,
            $ready ? 'pass' : ($production ? 'fail' : 'warn'),
            $ready
                ? 'ONNX et carte vérifiés · Python 3.12 · numpy 2.3.5 · Pillow 12.3.0 · ONNX Runtime 1.29.0 · fournisseur disponible'
                : ($artifactReady
                    ? 'artefacts vérifiés; environnement Python ou fournisseur ONNX incomplet'
                    : 'artefacts privés absents, empreintes non configurées ou carte invalide'),
        );
    }

    private function checkRentalUsageAnomalyRuntime(bool $production): void
    {
        $enabled = (bool) config('intelligence.rental_usage_anomaly.enabled');
        if (! $enabled) {
            $this->add(
                'Runtime usages atypiques',
                'warn',
                'désactivé par défaut; activation explicite requise après installation et contrôle',
            );

            return;
        }

        $binary = (string) config('intelligence.rental_usage_anomaly.python_binary');
        $script = (string) config('intelligence.rental_usage_anomaly.runtime_script');
        $configured = $binary !== '' && File::exists($script);
        $versions = null;
        if ($configured) {
            try {
                $probe = Process::path(sys_get_temp_dir())
                    ->timeout(8)
                    ->env([
                        'PYTHONDONTWRITEBYTECODE' => '1',
                        'OMP_NUM_THREADS' => '1',
                        'OPENBLAS_NUM_THREADS' => '1',
                        'MKL_NUM_THREADS' => '1',
                        'APP_KEY' => false,
                        'DB_PASSWORD' => false,
                        'MAIL_PASSWORD' => false,
                        'AWS_SECRET_ACCESS_KEY' => false,
                        'INTELLIGENCE_EXPORT_HMAC_KEY' => false,
                        'DEMO_PASSWORD' => false,
                        'PGPASSWORD' => false,
                    ])
                    ->run([
                        $binary,
                        '-c',
                        'import numpy,sklearn,sys; print(f\'{sys.version_info.major}.{sys.version_info.minor}|{numpy.__version__}|{sklearn.__version__}\')',
                    ]);
                if ($probe->successful()) {
                    $versions = trim($probe->output());
                }
            } catch (Throwable) {
                // Les chemins et stderr du runtime ne sont jamais exposés.
            }
        }
        $scriptDigest = $configured ? hash_file('sha256', $script) : false;
        $ready = $configured
            && is_string($scriptDigest)
            && preg_match('/^[a-f0-9]{64}$/D', $scriptDigest) === 1
            && $versions === '3.12|2.0.2|1.6.1';
        $this->add(
            'Runtime usages atypiques',
            $ready ? 'pass' : ($production ? 'fail' : 'warn'),
            $ready
                ? 'script versionné · CPU · Python 3.12 · numpy 2.0.2 · scikit-learn 1.6.1'
                : 'Python 3.12, numpy 2.0.2 et scikit-learn 1.6.1 requis',
        );
    }

    private function checkDatabase(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->add('Connexion PostgreSQL', 'fail', 'moteur configuré non pris en charge');

            return;
        }

        try {
            $driver = DB::connection()->getDriverName();
            $server = DB::selectOne("select current_setting('server_version') as version");
            $this->add('Connexion PostgreSQL', $driver === 'pgsql' ? 'pass' : 'fail', $driver.' '.$server->version);
            $expectedDatabase = $this->option('expect-database');
            if (is_string($expectedDatabase) && $expectedDatabase !== '') {
                $actualDatabase = DB::connection()->getDatabaseName();
                $this->add('Base attendue', hash_equals($expectedDatabase, $actualDatabase) ? 'pass' : 'fail', hash_equals($expectedDatabase, $actualDatabase) ? $actualDatabase : 'base différente');
            }
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
        $privatePath = (string) config('filesystems.disks.local.root');
        $privateReady = is_dir($privatePath) && is_writable($privatePath);
        $this->add('Stockage privé', $privateReady ? 'pass' : 'fail', $privateReady ? 'accessible en écriture' : 'absent ou non inscriptible');
        $cachePath = base_path('bootstrap/cache');
        $cacheReady = is_dir($cachePath) && is_writable($cachePath);
        $this->add('Cache bootstrap', $cacheReady ? 'pass' : 'fail', $cacheReady ? 'accessible en écriture' : 'absent ou non inscriptible');
        $this->add('Build frontend', File::exists(public_path('build/manifest.json')) ? 'pass' : 'fail', 'public/build/manifest.json');
    }

    private function checkWorkers(): void
    {
        $queueConnection = (string) config('queue.default');
        $activeRuns = null;
        try {
            $activeRuns = DB::table('fleet_reallocation_runs')
                ->whereIn('status', ['queued', 'running'])
                ->count();
            $activeRuns += DB::table('demand_forecast_execution_runs')
                ->whereIn('status', ['queued', 'running'])
                ->count();
            $activeRuns += DB::table('vehicle_color_prediction_runs')
                ->whereIn('status', ['queued', 'running'])
                ->count();
            $activeRuns += DB::table('vehicle_damage_prediction_runs')
                ->whereIn('status', ['queued', 'running'])
                ->count();
            $activeRuns += DB::table('rental_usage_anomaly_runs')
                ->whereIn('status', ['queued', 'running'])
                ->count();
        } catch (Throwable) {
            // La vérification des migrations rapporte séparément une table absente.
        }
        $queueDetail = $queueConnection === 'database'
            ? 'database (worker intelligence requis; '.($activeRuns ?? 0).' exécution(s) active(s))'
            : $queueConnection.' (database requis hors tests pour OR-Tools, HGB, couleur, dommages ONNX et usages atypiques)';
        $this->add('Queue', $queueConnection === 'database' ? 'pass' : 'warn', $queueDetail);

        try {
            $events = app(Schedule::class)->events();
            $heartbeatScheduled = collect($events)->contains(fn ($event) => str_contains((string) $event->command, 'operations:scheduler-heartbeat'));
            $reservationScheduled = collect($events)->contains(fn ($event) => str_contains((string) $event->command, 'reservations:expire-pending'));
            $insuranceScheduled = collect($events)->contains(fn ($event) => str_contains((string) $event->command, 'insurance:expire-policies'));
            $scheduled = $heartbeatScheduled && $reservationScheduled && $insuranceScheduled;
            $this->add('Scheduler', $scheduled ? 'pass' : 'fail', $scheduled ? 'heartbeat et expirations planifiés' : 'une commande planifiée attendue est absente');
        } catch (Throwable) {
            $this->add('Scheduler', 'fail', 'état non lisible');
        }

        $this->checkSchedulerHeartbeat();
    }

    private function checkSchedulerHeartbeat(): void
    {
        $production = app()->environment('production') || $this->option('production');
        $component = (string) config('operations.scheduler.heartbeat_component');
        $maxAge = (int) config('operations.scheduler.heartbeat_max_age_minutes');

        if (config('database.default') !== 'pgsql') {
            $this->add('Heartbeat scheduler', $production ? 'fail' : 'warn', 'non vérifiable sans PostgreSQL');

            return;
        }

        try {
            $lastSucceededAt = DB::table('operational_heartbeats')->where('component', $component)->value('last_succeeded_at');
            if (! $lastSucceededAt) {
                $this->add('Heartbeat scheduler', $production ? 'fail' : 'warn', 'absent');

                return;
            }

            $ageSeconds = CarbonImmutable::parse((string) $lastSucceededAt)->diffInSeconds(now(), true);
            $fresh = $ageSeconds <= ($maxAge * 60);
            $ageMinutes = (int) floor($ageSeconds / 60);
            $this->add('Heartbeat scheduler', $fresh ? 'pass' : ($production ? 'fail' : 'warn'), $fresh ? 'récent' : "ancien de {$ageMinutes} minute(s)");
        } catch (Throwable) {
            $this->add('Heartbeat scheduler', $production ? 'fail' : 'warn', 'indisponible');
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
