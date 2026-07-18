<?php

namespace Tests\Feature;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Insurance\ActivateInsurancePolicy;
use App\Actions\Insurance\AttachDemoInsurancePolicyProof;
use App\Actions\Insurance\CreateInsuranceCompany;
use App\Actions\Insurance\CreateInsuranceCoverage;
use App\Actions\Insurance\CreateInsurancePolicy;
use App\Actions\Vehicles\CreateVehicle;
use App\Enums\CustomerType;
use App\Enums\InsurancePolicyStatus;
use App\Enums\ReservationStatus;
use App\Enums\VerificationStatus;
use App\Models\Agency;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VehicleCategory;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

class Lot06FD2OperationsReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('documents.disk'));
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_scheduler_registers_timezone_locks_expirations_and_heartbeat(): void
    {
        $events = collect(app(Schedule::class)->events());

        foreach (['operations:scheduler-heartbeat', 'reservations:expire-pending', 'insurance:expire-policies'] as $command) {
            $event = $events->first(fn ($event) => str_contains((string) $event->command, $command));
            $this->assertNotNull($event, $command);
            $this->assertSame('Africa/Casablanca', $event->timezone);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertTrue($event->onOneServer);
        }

        $this->artisan('schedule:list')->expectsOutputToContain('operations:scheduler-heartbeat')->assertSuccessful();
    }

    public function test_scheduler_heartbeat_is_shared_non_sensitive_and_idempotent(): void
    {
        $this->artisan('operations:scheduler-heartbeat')->assertSuccessful();
        $first = DB::table('operational_heartbeats')->where('component', 'scheduler')->value('last_succeeded_at');
        $this->travel(10)->seconds();
        $this->artisan('operations:scheduler-heartbeat')->assertSuccessful();

        $this->assertSame(1, DB::table('operational_heartbeats')->count());
        $this->assertNotSame($first, DB::table('operational_heartbeats')->where('component', 'scheduler')->value('last_succeeded_at'));
        $this->assertSame(['component', 'last_succeeded_at'], array_keys((array) DB::table('operational_heartbeats')->first()));
    }

    public function test_doctor_warns_locally_and_fails_in_production_when_heartbeat_is_stale(): void
    {
        DB::table('operational_heartbeats')->insert([
            'component' => 'scheduler',
            'last_succeeded_at' => now()->subMinutes(10),
        ]);

        $this->assertSame('warn', $this->doctorCheck('Heartbeat scheduler')['status']);

        $this->app->detectEnvironment(fn () => 'production');
        $this->app->instance('env', 'production');
        config([
            'app.debug' => false,
            'app.url' => 'https://rentfleet.example.test',
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'database.default' => 'pgsql',
            'filesystems.default' => 'local',
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'cache.default' => 'database',
            'logging.default' => 'daily',
            'mail.mailers.smtp.scheme' => 'smtp',
        ]);

        $this->assertSame('fail', $this->doctorCheck('Heartbeat scheduler', ['--production' => true])['status']);
    }

    public function test_pending_reservation_expiration_is_idempotent(): void
    {
        $fixture = $this->operationalFixture();
        $reservation = $this->inTenant($fixture, fn () => Reservation::create([
            'agency_id' => $fixture['agency']->id,
            'customer_id' => $fixture['customer']->id,
            'vehicle_category_id' => $fixture['category']->id,
            'reservation_number' => 'RES-D2-'.uniqid(),
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'status' => ReservationStatus::Pending,
            'expires_at' => now()->subMinute(),
            'created_by' => $fixture['owner']->id,
        ]));

        $this->artisan('reservations:expire-pending')->assertSuccessful();
        $historyCount = DB::table('reservation_status_histories')->where('reservation_id', $reservation->id)->count();
        $this->artisan('reservations:expire-pending')->assertSuccessful();

        $this->assertSame(ReservationStatus::Expired, $this->inTenant($fixture, fn () => $reservation->refresh()->status));
        $this->assertSame(1, $historyCount);
        $this->assertSame($historyCount, DB::table('reservation_status_histories')->where('reservation_id', $reservation->id)->count());
    }

    public function test_insurance_policy_expiration_is_idempotent(): void
    {
        $fixture = $this->operationalFixture();
        $policy = $this->inTenant($fixture, function () use ($fixture) {
            $company = app(CreateInsuranceCompany::class)->handle(['name' => 'Assureur D2 '.uniqid()]);
            $policy = app(CreateInsurancePolicy::class)->handle([
                'agency_id' => $fixture['agency']->id,
                'vehicle_id' => $fixture['vehicle']->id,
                'insurance_company_id' => $company->id,
                'policy_number' => 'D2-POLICY-'.uniqid(),
                'policy_type' => 'comprehensive',
                'starts_at' => today()->subYear(),
                'ends_at' => today()->subDay(),
                'premium_amount' => '1200.00',
                'deductible_amount' => '500.00',
                'currency' => 'MAD',
            ], $fixture['owner']->id);
            app(CreateInsuranceCoverage::class)->handle($policy, [
                'coverage_type' => 'collision',
                'label' => 'Collision D2',
                'limit_amount' => '50000.00',
                'deductible_amount' => '500.00',
                'terms' => [],
            ]);
            app(AttachDemoInsurancePolicyProof::class)->handle($policy, $fixture['owner']->id, 'insurance.policy.document.seeded');

            return app(ActivateInsurancePolicy::class)->handle($policy, $fixture['owner']->id);
        });

        $this->artisan('insurance:expire-policies')->assertSuccessful();
        $historyCount = DB::table('insurance_policy_status_histories')->where('insurance_policy_id', $policy->id)->count();
        $this->artisan('insurance:expire-policies')->assertSuccessful();

        $this->assertSame(InsurancePolicyStatus::Expired, $this->inTenant($fixture, fn () => $policy->refresh()->status));
        $this->assertSame($historyCount, DB::table('insurance_policy_status_histories')->where('insurance_policy_id', $policy->id)->count());
    }

    public function test_production_doctor_refuses_debug_non_postgresql_and_public_documents(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->app->instance('env', 'production');
        config([
            'app.debug' => true,
            'database.default' => 'mysql',
            'filesystems.default' => 'public',
        ]);

        try {
            Artisan::call('rentfleet:doctor', ['--production' => true, '--json' => true]);
            $checks = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks'])->keyBy('name');

            $this->assertSame('fail', $checks['Mode debug']['status']);
            $this->assertSame('fail', $checks['Base de données production']['status']);
            $this->assertSame('fail', $checks['Stockage documentaire production']['status']);
        } finally {
            config([
                'app.debug' => false,
                'database.default' => 'pgsql',
                'filesystems.default' => 'local',
            ]);
            $this->app->instance('env', 'testing');
        }
    }

    public function test_doctor_can_guard_the_exact_testing_database_before_destructive_tests(): void
    {
        $this->assertSame('pass', $this->doctorCheck('Base attendue', ['--expect-database' => 'rentfleet_test'])['status']);
        $this->assertSame('fail', $this->doctorCheck('Base attendue', ['--expect-database' => 'rentfleet'])['status']);
    }

    public function test_demo_seeding_is_rejected_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->app->instance('env', 'production');
        $this->expectException(LogicException::class);

        $this->seed(DatabaseSeeder::class);
    }

    public function test_backup_refuses_an_unauthorized_or_empty_database_before_any_tool_call(): void
    {
        foreach (['postgres', 'rentfleet_restore_test', ''] as $database) {
            $result = $this->runScript('backup.ps1', [
                '-DatabaseName', $database,
                '-OutputDirectory', 'backups',
            ]);

            $this->assertTrue($result->failed());
            $this->assertStringContainsString('rentfleet_test', $result->output().$result->errorOutput());
        }
    }

    public function test_restore_refuses_protected_empty_and_variant_database_names(): void
    {
        foreach (['rentfleet', 'rentfleet_test', 'postgres', 'template0', 'template1', 'rentfleet_restore_test_copy', ''] as $database) {
            $result = $this->runScript('restore.ps1', [
                '-BackupDirectory', 'missing-backup',
                '-DatabaseName', $database,
                '-PrivateDocumentsTarget', $this->isolatedPath('target'),
                '-ConfirmRestore',
            ]);

            $this->assertTrue($result->failed());
            $this->assertStringContainsString('rentfleet_restore_test', $result->output().$result->errorOutput());
            $this->assertStringNotContainsString('pg_restore a échoué', $result->output().$result->errorOutput());
        }
    }

    public function test_restore_requires_confirmation_and_only_then_checks_artifacts(): void
    {
        $arguments = [
            '-BackupDirectory', 'missing-backup',
            '-DatabaseName', 'rentfleet_restore_test',
            '-PrivateDocumentsTarget', $this->isolatedPath('target'),
        ];

        $withoutConfirmation = $this->runScript('restore.ps1', $arguments);
        $this->assertTrue($withoutConfirmation->failed());
        $this->assertStringContainsString('-ConfirmRestore', $withoutConfirmation->output().$withoutConfirmation->errorOutput());

        $withConfirmation = $this->runScript('restore.ps1', [...$arguments, '-ConfirmRestore']);
        $this->assertTrue($withConfirmation->failed());
        $this->assertStringContainsString('Dossier de sauvegarde introuvable', $withConfirmation->output().$withConfirmation->errorOutput());
    }

    public function test_restore_fails_on_an_invalid_sha_before_pgpass_or_pg_restore(): void
    {
        $backup = storage_path('framework/testing/d2-invalid-backup-'.uniqid());
        File::ensureDirectoryExists($backup);
        File::put($backup.'/database.dump', 'x');
        File::put($backup.'/private.zip', 'x');
        File::put($backup.'/backup-manifest.json', json_encode([
            'schema_version' => 1,
            'status' => 'completed',
            'source_database' => 'rentfleet',
            'artifacts' => [
                'database' => ['name' => 'database.dump', 'size_bytes' => 1, 'sha256' => str_repeat('0', 64)],
                'private_documents' => ['name' => 'private.zip', 'size_bytes' => 1, 'sha256' => str_repeat('0', 64), 'file_count' => 0],
            ],
            'private_files' => [],
        ], JSON_THROW_ON_ERROR));

        try {
            $result = $this->runScript('restore.ps1', [
                '-BackupDirectory', $backup,
                '-DatabaseName', 'rentfleet_restore_test',
                '-PrivateDocumentsTarget', $this->isolatedPath('target'),
                '-ConfirmRestore',
            ]);

            $this->assertTrue($result->failed());
            $this->assertStringContainsString('SHA-256 invalide', $result->output().$result->errorOutput());
            $this->assertStringNotContainsString('pgpass est absent', $result->output().$result->errorOutput());
        } finally {
            File::deleteDirectory($backup);
        }
    }

    public function test_restore_verification_fails_on_an_invalid_sha_and_uses_php_binary(): void
    {
        $base = $this->isolatedPath('verify');
        $backup = $base.DIRECTORY_SEPARATOR.'backup';
        $private = $base.DIRECTORY_SEPARATOR.'private';
        File::ensureDirectoryExists($backup);
        File::ensureDirectoryExists($private);
        File::put($backup.'/database.dump', 'x');
        File::put($backup.'/private.zip', 'x');
        $manifest = json_encode([
            'schema_version' => 1,
            'status' => 'completed',
            'source_database' => 'rentfleet',
            'artifacts' => [
                'database' => ['name' => 'database.dump', 'size_bytes' => 1, 'sha256' => str_repeat('0', 64)],
                'private_documents' => ['name' => 'private.zip', 'size_bytes' => 1, 'sha256' => str_repeat('0', 64), 'file_count' => 0],
            ],
            'private_files' => [],
        ], JSON_THROW_ON_ERROR);
        File::put($backup.'/backup-manifest.json', $manifest);
        File::put($private.'/backup-manifest.json', $manifest);

        try {
            $result = Process::env(['PHP_BINARY' => PHP_BINARY])->timeout(20)->run([
                'powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass',
                '-File', base_path('scripts/verify-restore.ps1'),
                '-BackupDirectory', $backup,
                '-DatabaseName', 'rentfleet_restore_test',
                '-PrivateDocumentsPath', $private,
            ]);

            $this->assertTrue($result->failed());
            $this->assertStringContainsString('SHA-256 invalide', $result->output().$result->errorOutput());
            $this->assertStringNotContainsString('pgpass est absent', $result->output().$result->errorOutput());
        } finally {
            File::deleteDirectory($base);
        }
    }

    public function test_scripts_require_hashes_sizes_php_binary_and_never_expose_passwords(): void
    {
        $scripts = collect(['backup.ps1', 'restore.ps1', 'verify-restore.ps1'])
            ->map(fn (string $file) => File::get(base_path('scripts/'.$file)))
            ->implode("\n");

        foreach (['size_bytes', 'sha256', 'private_files', '--no-password', 'PGPASSFILE'] as $expected) {
            $this->assertStringContainsString($expected, $scripts);
        }
        $this->assertStringContainsString('PHP_BINARY', File::get(base_path('scripts/verify-restore.ps1')));
        $this->assertStringNotContainsString('DB_PASSWORD', $scripts);
        $this->assertStringNotContainsString('PGPASSWORD', $scripts);
        $this->assertStringNotContainsString('Get-Content .env', $scripts);
        $this->assertDoesNotMatchRegularExpression('/password\s*=\s*[\'\"][^\'\"]+/i', $scripts);
    }

    public function test_private_archive_exclusions_manifest_and_safe_cleanup_are_explicit(): void
    {
        $backup = File::get(base_path('scripts/backup.ps1'));
        $restore = File::get(base_path('scripts/restore.ps1'));

        foreach (['logs?', 'cache', 'sessions?', 'build', 'temp', '.env', '.key'] as $excluded) {
            $this->assertStringContainsString($excluded, $backup);
        }
        $this->assertStringContainsString('ReparsePoint', $backup);
        $this->assertStringContainsString('application_commit', $backup);
        $this->assertStringContainsString('postgres_version', $backup);
        $this->assertStringContainsString('--single-transaction', $restore);
        $this->assertStringContainsString('distinct du stockage vivant', $restore);
    }

    public function test_all_powershell_scripts_parse_without_executing_restore(): void
    {
        foreach (File::glob(base_path('scripts/*.ps1')) as $script) {
            $command = '$tokens=$null; $errors=$null; [System.Management.Automation.Language.Parser]::ParseFile('.
                var_export($script, true).',[ref]$tokens,[ref]$errors) | Out-Null; if($errors.Count){$errors | % Message; exit 1}';
            $result = Process::timeout(15)->run(['powershell.exe', '-NoProfile', '-Command', $command]);

            $this->assertTrue($result->successful(), basename($script).': '.$result->errorOutput().$result->output());
        }
    }

    public function test_deploy_check_is_non_destructive_and_checks_release_guards(): void
    {
        $source = File::get(base_path('scripts/deploy-check.ps1'));

        foreach (['PHP_BINARY', 'pdo_pgsql', 'composer', 'node', 'psql', 'migrate:status', 'rentfleet:doctor', 'expect-database=rentfleet_test', 'schedule:list', 'config:cache', 'route:cache', 'view:cache', 'optimize', 'public/build/manifest.json'] as $expected) {
            $this->assertStringContainsString($expected, $source);
        }
        foreach (['migrate:fresh', 'db:wipe', 'DROP DATABASE', 'pg_restore'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    public function test_health_errors_and_routes_expose_no_secret_or_public_storage(): void
    {
        $health = $this->getJson('/health')->assertOk();
        $error = $this->get('/route-d2-inexistante')->assertNotFound();
        $content = mb_strtolower($health->getContent().$error->getContent());

        foreach (['password', 'app_key', 'db_password', 'storage/app/private'] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }

        $uris = collect(Route::getRoutes())->map(fn ($route) => $route->uri());
        $this->assertFalse($uris->contains(fn (string $uri) => preg_match('#(^|/)(register|signup)(/|$)|^storage/#', $uri) === 1));
    }

    private function doctorCheck(string $name, array $options = []): array
    {
        Artisan::call('rentfleet:doctor', [...$options, '--json' => true]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        return collect($report['checks'])->firstWhere('name', $name) ?? [];
    }

    private function operationalFixture(): array
    {
        $tenant = Tenant::factory()->create(['name' => 'Tenant Opérations D2']);
        $agency = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create(['name' => 'Agence D2']));
        $owner = User::factory()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => null,
            'role_id' => Role::where('slug', 'tenant-owner')->value('id'),
        ]);
        $fixture = compact('tenant', 'agency', 'owner');

        return $this->inTenant($fixture, function () use ($fixture) {
            $category = VehicleCategory::create(['code' => 'D2-'.uniqid(), 'name' => 'Catégorie D2', 'is_active' => true]);
            $vehicle = app(CreateVehicle::class)->handle([
                'agency_id' => $fixture['agency']->id,
                'vehicle_category_id' => $category->id,
                'registration_number' => 'D2-'.uniqid(),
                'brand' => 'Dacia',
                'model' => 'Logan',
                'production_year' => 2025,
                'fuel_type' => 'diesel',
                'transmission' => 'manual',
                'current_mileage' => 1000,
            ], $fixture['owner']->id);
            $customer = app(CreateCustomer::class)->handle([
                'agency_id' => $fixture['agency']->id,
                'customer_type' => CustomerType::Individual,
                'first_name' => 'Client',
                'last_name' => 'Opérations',
                'verification_status' => VerificationStatus::Verified,
            ]);

            return [...$fixture, 'category' => $category, 'vehicle' => $vehicle, 'customer' => $customer];
        });
    }

    private function inTenant(array $fixture, callable $callback): mixed
    {
        return app(TenantContext::class)->run($fixture['tenant'], $callback, $fixture['agency']->id);
    }

    private function runScript(string $script, array $arguments)
    {
        return Process::timeout(20)->run([
            'powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass',
            '-File', base_path('scripts/'.$script),
            ...$arguments,
        ]);
    }

    private function isolatedPath(string $suffix): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'rentfleet-d2-'.$suffix.'-'.uniqid();
    }
}
