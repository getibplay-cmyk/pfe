<?php

namespace Tests\Feature;

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
        $this->assertDoesNotMatchRegularExpression('/DB_PASSWORD=.+/', $example);
        $this->assertDoesNotMatchRegularExpression('/APP_KEY=.+/', $example);
        $this->assertStringNotContainsString('sqlite', strtolower($example));
        $this->assertStringNotContainsString(':memory:', strtolower($example));
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
        $databaseSeeder = file_get_contents(database_path('seeders/DatabaseSeeder.php'));
        $tenancySeeder = file_get_contents(database_path('seeders/DemoTenancySeeder.php'));

        $this->assertStringContainsString("environment('production')", $databaseSeeder);
        $this->assertStringNotContainsString("env('DEMO_PASSWORD',", $tenancySeeder);
        $this->assertStringContainsString('Str::password(24)', $tenancySeeder);
    }
}
