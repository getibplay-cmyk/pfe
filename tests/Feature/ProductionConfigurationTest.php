<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ProductionConfigurationTest extends TestCase
{
    public function test_production_example_is_secure_postgresql_only_and_secret_free(): void
    {
        $example = file_get_contents(base_path('.env.production.example'));

        $this->assertStringContainsString('APP_ENV=production', $example);
        $this->assertStringContainsString('APP_DEBUG=false', $example);
        $this->assertStringContainsString('DB_CONNECTION=pgsql', $example);
        $this->assertStringContainsString('SESSION_ENCRYPT=true', $example);
        $this->assertStringContainsString('SESSION_SECURE_COOKIE=true', $example);
        $this->assertStringContainsString('QUEUE_CONNECTION=database', $example);
        $this->assertStringContainsString('CACHE_STORE=database', $example);
        $this->assertStringNotContainsString('sqlite', strtolower($example));
        $this->assertStringNotContainsString(':memory:', strtolower($example));
    }

    public function test_every_versioned_environment_example_has_only_empty_or_null_sensitive_values(): void
    {
        $violations = [];

        foreach (File::glob(base_path('.env*.example')) as $path) {
            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $lineNumber => $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($trimmed, '=')) {
                    continue;
                }

                [$key, $value] = array_map('trim', explode('=', $trimmed, 2));
                if (! $this->isSensitiveEnvironmentKey($key)) {
                    continue;
                }

                $unquoted = trim($value, "\"'");
                if ($unquoted !== '' && strtolower($unquoted) !== 'null') {
                    $violations[] = basename($path).':'.($lineNumber + 1).':'.$key;
                }
            }
        }

        $this->assertSame([], $violations, 'Valeurs sensibles non vides détectées : '.implode(', ', $violations));
    }

    public function test_production_smtp_example_uses_the_variable_read_by_mail_configuration(): void
    {
        $example = file_get_contents(base_path('.env.production.example'));
        $mailConfig = file_get_contents(config_path('mail.php'));

        $this->assertStringContainsString("env('MAIL_SCHEME')", $mailConfig);
        $this->assertStringContainsString('MAIL_SCHEME=', $example);
        $this->assertStringNotContainsString('MAIL_ENCRYPTION=', $example);
    }

    public function test_application_defaults_do_not_fall_back_to_sqlite(): void
    {
        $database = file_get_contents(config_path('database.php'));
        $queue = file_get_contents(config_path('queue.php'));
        $composer = file_get_contents(base_path('composer.json'));

        $this->assertSame('pgsql', config('database.default'));
        $this->assertArrayNotHasKey('sqlite', config('database.connections'));
        $this->assertStringNotContainsString("env('DB_CONNECTION', 'sqlite')", $database.$queue);
        $this->assertStringNotContainsString('database.sqlite', $composer);
    }

    public function test_demo_seeders_are_blocked_in_production_and_have_no_fixed_password(): void
    {
        $tenancySeeder = file_get_contents(database_path('seeders/DemoTenancySeeder.php'));
        $demoSeeders = [
            'DatabaseSeeder.php',
            'DemoTenancySeeder.php',
            'Lot02DemoSeeder.php',
            'Lot03DemoSeeder.php',
            'Lot04DemoSeeder.php',
            'Lot05DemoSeeder.php',
        ];

        foreach ($demoSeeders as $seeder) {
            $source = file_get_contents(database_path('seeders/'.$seeder));
            $this->assertStringContainsString('PreventsDemoSeedingInProduction', $source, $seeder);
            $this->assertStringContainsString('ensureDemoSeedingIsAllowed()', $source, $seeder);
        }

        $this->assertStringContainsString("env('DEMO_PASSWORD')", $tenancySeeder);
        $this->assertStringContainsString('Str::password(24)', $tenancySeeder);
        $this->assertDoesNotMatchRegularExpression('/Hash::make\(\s*[\'\"][^\'\"]+[\'\"]\s*\)/', $tenancySeeder);
    }

    public function test_versioned_runtime_sources_have_no_hard_coded_password_or_secret(): void
    {
        $directories = [app_path(), config_path(), database_path('seeders'), base_path('routes')];
        $patterns = [
            'literal_hash' => '/Hash::make\(\s*[\'\"][^\'\"]+[\'\"]\s*\)/',
            'literal_password_array' => '/[\'\"]password[\'\"]\s*=>\s*[\'\"](?!hashed[\'\"])[^\'\"]+[\'\"]/',
            'sensitive_env_default' => '/env\(\s*[\'\"](?:[A-Z0-9_]*(?:PASSWORD|SECRET|TOKEN|API_KEY)|APP_KEY)[\'\"]\s*,\s*[\'\"][^\'\"]+[\'\"]\s*\)/i',
            'literal_secret_assignment' => '/\b(?:password|secret|token|api_key)\b\s*=\s*[\'\"][^\'\"]+[\'\"]/i',
        ];
        $violations = [];

        foreach ($directories as $directory) {
            foreach (File::allFiles($directory) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = $file->getContents();
                foreach ($patterns as $name => $pattern) {
                    if (preg_match($pattern, $source) === 1) {
                        $violations[] = $file->getRelativePathname().':'.$name;
                    }
                }
            }
        }

        $this->assertSame([], $violations, 'Secrets potentiels détectés : '.implode(', ', $violations));
    }

    private function isSensitiveEnvironmentKey(string $key): bool
    {
        return $key === 'APP_KEY'
            || preg_match('/(?:PASSWORD|SECRET|TOKEN|API_KEY|ACCESS_KEY|PRIVATE_KEY|CLIENT_SECRET)/i', $key) === 1;
    }
}
